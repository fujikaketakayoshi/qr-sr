<?php

declare(strict_types=1);

namespace QrRally\Controller;

use QrRally\Http\Request;
use QrRally\Http\Response;
use QrRally\Install\EnvironmentInspector;
use QrRally\Install\InstallException;
use QrRally\Install\InstallInput;
use QrRally\Install\Installer;
use QrRally\Security\CsrfToken;
use QrRally\View\TemplateRenderer;
use Throwable;

final class InstallController
{
    public function __construct(
        private readonly TemplateRenderer $templates,
        private readonly CsrfToken $csrf,
        private readonly EnvironmentInspector $inspector,
        private readonly Installer $installer,
        private readonly string $assetBase,
        private readonly string $productVersion,
    ) {
    }

    /** @param array<string, mixed> $server */
    public function handle(Request $request, array $server): Response
    {
        $values = $this->defaultValues($server);
        if ($request->method() === 'GET') {
            $state = $_SESSION['_install_restore'] ?? null;
            if (is_array($state) && (int) ($state['expires_at'] ?? 0) >= time() && is_array($state['input'] ?? null)) {
                return $this->page($server, $state['input'], [], 200, $state);
            }
            $this->clearRestoreState();
            return $this->page($server, $values);
        }
        if (!$this->csrf->verify($request->input('_csrf'))) {
            return $this->page($server, $values, ['form' => '画面を開き直して、もう一度操作してください。'], 419);
        }
        $action = $request->input('action');
        if ($action === 'cancel_restore') {
            $this->clearRestoreState();
            return Response::redirect($this->assetBase . 'install');
        }
        if ($action === 'execute_restore') {
            return $this->executeRestore($request, $server);
        }

        $values = $this->postedValues($request);
        $input = $this->input($request, $values);
        try {
            if ($action === 'new_install') {
                $result = $this->installer->install($input, $server);
                return $this->result('install/recovery-key.php', [
                    'title' => 'インストール完了',
                    'recoveryKey' => $result['recovery_key'],
                    'loginUrl' => $result['login_url'],
                    'baseUrl' => $input->baseUrl,
                    'privatePath' => $input->privatePath,
                    'pathDiagnostics' => $result['path_diagnostics'],
                ]);
            }
            if ($action === 'validate_restore') {
                $this->clearRestoreState();
                $file = $request->uploadedFile('backup');
                if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
                    throw new InstallException('バックアップZIPを選択してください。', ['backup' => 'アップロードされたZIPを読み取れません。']);
                }
                $validated = $this->installer->validateRestore($input, $server, $file['tmp_name'], $file['size']);
                $_SESSION['_install_restore'] = [
                    'expires_at' => time() + 3600,
                    'input' => $values,
                    'database_path' => $validated['database_path'],
                    'work_directory' => $validated['work_directory'],
                    'manifest' => $validated['manifest'],
                    'path_diagnostics' => $validated['path_diagnostics'],
                ];
                return $this->page($server, $values, [], 200, $_SESSION['_install_restore']);
            }
            throw new InstallException('操作を確認できません。');
        } catch (InstallException $error) {
            $errors = $error->errors();
            if ($errors === []) {
                $errors['form'] = $error->getMessage();
            }
            return $this->page($server, $values, $errors, 422);
        } catch (Throwable) {
            return $this->page($server, $values, ['form' => '処理に失敗しました。入力内容とサーバー設定を確認してください。'], 500);
        }
    }

    /** @param array<string, mixed> $server */
    private function executeRestore(Request $request, array $server): Response
    {
        $state = $_SESSION['_install_restore'] ?? null;
        if (!is_array($state) || (int) ($state['expires_at'] ?? 0) < time()
            || !is_array($state['input'] ?? null) || !is_array($state['manifest'] ?? null)
            || !is_string($state['database_path'] ?? null)) {
            $this->clearRestoreState();
            return $this->page($server, $this->defaultValues($server), ['form' => '復元確認の有効期限が切れました。ZIPの検証からやり直してください。'], 422);
        }
        $values = $state['input'];
        if ($request->input('confirmation') !== '復元する') {
            return $this->page($server, $values, ['confirmation' => '確認のため「復元する」と入力してください。'], 422, $state);
        }
        try {
            $input = new InstallInput(
                (string) ($values['environment'] ?? ''),
                (string) ($values['base_url'] ?? ''),
                (string) ($values['private_path'] ?? ''),
                (string) ($values['timezone'] ?? ''),
            );
            $result = $this->installer->restore($input, $server, $state['database_path'], $state['manifest']);
            unset($_SESSION['_install_restore']);
            return $this->result('install/restore-complete.php', [
                'title' => '復元完了',
                'loginUrl' => $result['login_url'],
                'sourceBaseUrl' => $result['source_base_url'],
                'baseUrl' => $input->baseUrl,
            ]);
        } catch (InstallException $error) {
            $this->clearRestoreState();
            return $this->page($server, $values, ['form' => $error->getMessage() . ' ZIPの検証からやり直してください。'], 422);
        } catch (Throwable) {
            $this->clearRestoreState();
            return $this->page($server, $values, ['form' => '復元に失敗しました。正式な設定は作成されていません。ZIPの検証からやり直してください。'], 500);
        }
    }

    /** @param array<string, mixed> $server
     *  @param array<string, string> $values
     *  @param array<string, string> $errors
     *  @param array<string, mixed>|null $restoreState
     */
    private function page(array $server, array $values, array $errors = [], int $status = 200, ?array $restoreState = null): Response
    {
        $body = $this->templates->renderWithLayout('install/index.php', [
            'title' => 'セットアップ',
            'assetBase' => $this->assetBase,
            'csrf' => $this->csrf->get(),
            'diagnostics' => $this->inspector->basic($server),
            'values' => $values,
            'errors' => $errors,
            'restoreState' => $restoreState,
            'productVersion' => $this->productVersion,
        ], 'install/layout.php');
        return new Response($body, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function result(string $template, array $data): Response
    {
        $data['assetBase'] = $this->assetBase;
        return new Response($this->templates->renderWithLayout($template, $data, 'install/layout.php'), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** @param array<string, mixed> $server
     *  @return array<string, string>
     */
    private function defaultValues(array $server): array
    {
        return [
            'environment' => $this->inspector->isHttps($server) ? 'production' : 'development',
            'base_url' => $this->inspector->candidateBaseUrl($server),
            'private_path' => $this->inspector->candidatePrivatePath($server),
            'timezone' => 'Asia/Tokyo',
            'email' => '',
        ];
    }

    /** @return array<string, string> */
    private function postedValues(Request $request): array
    {
        return [
            'environment' => $request->input('environment'),
            'base_url' => $request->input('base_url'),
            'private_path' => $request->input('private_path'),
            'timezone' => $request->input('timezone', 'Asia/Tokyo'),
            'email' => $request->input('email'),
        ];
    }

    /** @param array<string, string> $values */
    private function input(Request $request, array $values): InstallInput
    {
        return new InstallInput(
            $values['environment'],
            $values['base_url'],
            $values['private_path'],
            $values['timezone'],
            $values['email'],
            $request->input('password'),
            $request->input('password_confirmation'),
        );
    }

    private function clearRestoreState(): void
    {
        $state = $_SESSION['_install_restore'] ?? null;
        if (is_array($state) && is_string($state['work_directory'] ?? null)) {
            $directory = $state['work_directory'];
            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
        unset($_SESSION['_install_restore']);
    }
}
