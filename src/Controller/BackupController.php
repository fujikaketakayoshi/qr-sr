<?php

declare(strict_types=1);

namespace QrRally\Controller;

use QrRally\Auth\AdminAuth;
use QrRally\Backup\BackupException;
use QrRally\Backup\BackupManager;
use QrRally\Backup\RestoreStateStore;
use QrRally\Http\Request;
use QrRally\Http\Response;
use QrRally\Repository\AdminRepository;
use QrRally\Repository\ApplicationRepository;
use QrRally\Repository\AuditLogRepository;
use QrRally\Repository\BackupOperationRepository;
use QrRally\Repository\EventRepository;
use QrRally\Security\CsrfToken;
use QrRally\Session\Flash;
use QrRally\Support\UrlGenerator;
use QrRally\Support\ViewData;
use QrRally\Support\DemoMode;
use QrRally\View\TemplateRenderer;
use Throwable;

final class BackupController
{
    public function __construct(
        private readonly TemplateRenderer $templates,
        private readonly ViewData $views,
        private readonly UrlGenerator $urls,
        private readonly CsrfToken $csrf,
        private readonly Flash $flash,
        private readonly AdminAuth $auth,
        private readonly AdminRepository $admins,
        private readonly EventRepository $events,
        private readonly ApplicationRepository $applications,
        private readonly BackupOperationRepository $operations,
        private readonly AuditLogRepository $logs,
        private readonly BackupManager $backups,
        private readonly RestoreStateStore $states,
        private readonly string $baseUrl,
        private readonly DemoMode $demoMode,
    ) {
    }

    public function index(?string $error = null, int $status = 200): Response
    {
        if (($response = $this->requireAdmin()) !== null) {
            return $response;
        }
        $state = $this->states->read();
        return $this->view('admin/backup.php', [
            'title' => 'バックアップ・復元',
            'error' => $error,
            'event' => $this->events->find(),
            'summary' => $this->applications->summary(),
            'estimatedSize' => $this->backups->databaseSize(),
            'state' => $state,
            'manifest' => is_array($state['manifest'] ?? null) ? $state['manifest'] : null,
            'sourceUrlDiffers' => isset($state['manifest']['source_base_url'])
                && $state['manifest']['source_base_url'] !== $this->baseUrl,
            'operations' => $this->operations->recent(),
        ], $status);
    }

    public function download(Request $request): Response
    {
        if (($response = $this->requireAdmin()) !== null) {
            return $response;
        }
        if ($this->demoMode->enabled()) {
            return $this->demoRestricted();
        }
        if (!$this->csrf->verify($request->input('_csrf'))) {
            return $this->csrfFailure();
        }
        if (!$this->verifyPassword($request->input('password'))) {
            return $this->index('現在の管理者パスワードが正しくありません。', 422);
        }
        try {
            $result = $this->backups->create();
            $body = file_get_contents($result['path']);
            if ($body === false) {
                throw new BackupException('バックアップを読み取れません。');
            }
            $this->operations->record('backup', 'completed', $result['hash']);
            $this->logs->record('backup.download', 'admin', $this->auth->id(), 'success', ['file_hash' => $result['hash']]);
            $this->backups->cleanup(dirname($result['path']));
            return new Response($body, 200, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
                'Content-Length' => (string) strlen($body),
                'Cache-Control' => 'private, no-store',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable $error) {
            $this->operations->record('backup', 'failed', '', $error->getMessage());
            $this->logs->record('backup.download', 'admin', $this->auth->id(), 'failed');
            return $this->index($error instanceof BackupException ? $error->getMessage() : 'バックアップを作成できませんでした。', 422);
        }
    }

    public function upload(Request $request): Response
    {
        if (($response = $this->requireAdmin()) !== null) {
            return $response;
        }
        if ($this->demoMode->enabled()) {
            return $this->demoRestricted();
        }
        if (!$this->csrf->verify($request->input('_csrf'))) {
            return $this->csrfFailure();
        }
        if (!$this->verifyPassword($request->input('password'))) {
            return $this->index('現在の管理者パスワードが正しくありません。', 422);
        }
        $file = $request->uploadedFile('backup_file');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            return $this->index('バックアップZIPを選択してください。', 422);
        }
        $this->states->clear();
        try {
            $validated = $this->backups->validateUploaded($file['tmp_name'], $file['size']);
            $state = [
                'operation_id' => bin2hex(random_bytes(16)),
                'status' => 'ready',
                'maintenance' => false,
                'work_directory' => $validated['work_directory'],
                'database_path' => $validated['database_path'],
                'archive_hash' => $validated['archive_hash'],
                'manifest' => $validated['manifest'],
                'error_summary' => null,
            ];
            $this->states->write($state);
            $this->operations->record('restore', 'ready', $validated['archive_hash']);
            $this->logs->record('backup.restore.validate', 'admin', $this->auth->id(), 'success', ['file_hash' => $validated['archive_hash']]);
            $this->flash->set('success', 'バックアップの検証が完了しました。画面下部の「復元内容の確認」に進んで内容を確認してください。');
            return Response::redirect($this->urls->to('admin/backup'));
        } catch (Throwable $error) {
            $this->operations->record('restore', 'failed', '', $error->getMessage());
            $this->logs->record('backup.restore.validate', 'admin', $this->auth->id(), 'failed');
            return $this->index($error instanceof BackupException ? $error->getMessage() : 'バックアップを検証できませんでした。', 422);
        }
    }

    public function restore(Request $request): Response
    {
        if (($response = $this->requireAdmin()) !== null) {
            return $response;
        }
        if ($this->demoMode->enabled()) {
            return $this->demoRestricted();
        }
        if (!$this->csrf->verify($request->input('_csrf'))) {
            return $this->csrfFailure();
        }
        $state = $this->states->read();
        if (!is_array($state) || ($state['status'] ?? null) !== 'ready'
            || !isset($state['database_path'], $state['manifest']) || !is_string($state['database_path']) || !is_array($state['manifest'])) {
            return $this->index('検証済みの復元データがありません。', 422);
        }
        if (!$this->verifyPassword($request->input('password'))) {
            return $this->index('現在の管理者パスワードが正しくありません。', 422);
        }
        if ($request->input('confirmation') !== '復元する') {
            return $this->index('確認欄へ「復元する」と入力してください。', 422);
        }
        $adminId = $this->auth->id();
        try {
            $this->backups->restore($state['database_path'], $state['manifest'], $this->states, (int) $adminId);
            $this->auth->forceLogout();
            return Response::redirect($this->urls->to('admin/login?restored=1'));
        } catch (Throwable $error) {
            return $this->index($error instanceof BackupException ? $error->getMessage() : '復元に失敗したため、元のデータを維持しました。', 422);
        }
    }

    private function verifyPassword(string $password): bool
    {
        $id = $this->auth->id();
        $admin = $id === null ? null : $this->admins->findById($id);
        return $admin !== null && password_verify($password, (string) $admin['password_hash']);
    }

    private function requireAdmin(): ?Response
    {
        return $this->auth->id() === null ? Response::redirect($this->urls->to('admin/login')) : null;
    }

    private function csrfFailure(): Response
    {
        return $this->view('errors/419.php', ['title' => '操作を続けられません'], 419, false);
    }

    private function demoRestricted(): Response
    {
        return $this->view('errors/demo-restricted.php', [
            'title' => '公開デモでは使用できません',
        ], 403);
    }

    /** @param array<string, mixed> $data */
    private function view(string $template, array $data, int $status = 200, bool $layout = true): Response
    {
        $data = array_merge($this->views->common(), $data);
        return new Response($this->templates->renderWithLayout($template, $data, $layout ? 'admin/layout.php' : 'auth/layout.php'), $status);
    }
}
