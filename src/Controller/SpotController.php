<?php

declare(strict_types=1);

namespace QrRally\Controller;

use DateTimeImmutable;
use DateTimeZone;
use QrRally\Auth\AdminAuth;
use QrRally\Domain\SpotInput;
use QrRally\Domain\SpotValidator;
use QrRally\Http\Request;
use QrRally\Http\Response;
use QrRally\Repository\AuditLogRepository;
use QrRally\Repository\EventRepository;
use QrRally\Repository\SpotRepository;
use QrRally\Security\CsrfToken;
use QrRally\Session\Flash;
use QrRally\Support\QrCodeGenerator;
use QrRally\Support\DownloadFilename;
use QrRally\Support\UrlGenerator;
use QrRally\Support\ViewData;
use QrRally\View\TemplateRenderer;
use RuntimeException;

final class SpotController
{
    public function __construct(
        private readonly TemplateRenderer $templates,
        private readonly ViewData $views,
        private readonly UrlGenerator $urls,
        private readonly CsrfToken $csrf,
        private readonly Flash $flash,
        private readonly AdminAuth $auth,
        private readonly SpotRepository $spots,
        private readonly EventRepository $events,
        private readonly AuditLogRepository $logs,
        private readonly SpotValidator $validator,
        private readonly QrCodeGenerator $qrCodes,
        private readonly DownloadFilename $filenames,
    ) {
    }

    public function index(): Response
    {
        if (($response = $this->requireAdmin()) !== null) {
            return $response;
        }
        $event = $this->events->find();

        return $this->view('admin/spots/index.php', [
            'title' => 'スポット管理',
            'spots' => $this->spots->all(),
            'event' => $event,
            'canDelete' => $event !== null && new DateTimeImmutable('now', new DateTimeZone('UTC')) < new DateTimeImmutable((string) $event['starts_at']),
        ]);
    }

    public function createForm(array $errors = [], ?SpotInput $input = null, int $status = 200): Response
    {
        if (($response = $this->requireAdmin()) !== null) {
            return $response;
        }

        return $this->view('admin/spots/form.php', [
            'title' => 'スポット追加',
            'spot' => null,
            'values' => ['name' => $input?->name ?? '', 'description' => $input?->description ?? ''],
            'errors' => $errors,
        ], $status);
    }

    public function create(Request $request): Response
    {
        if (($response = $this->guardPost($request)) !== null) {
            return $response;
        }
        if ($this->events->find() === null) {
            $this->flash->set('error', '先にイベント設定を作成してください。');
            return Response::redirect($this->urls->to('admin/spots'));
        }
        $input = new SpotInput($request->input('name'), $request->input('description'));
        $errors = $this->validator->validate($input);
        if ($errors !== []) {
            return $this->createForm($errors, $input, 422);
        }

        $id = $this->spots->create($input);
        $this->logs->record('spot.created', 'admin', $this->auth->id(), 'success', [], 'spot', $id);
        $this->flash->set('success', 'スポットを追加しました。QRコードを実機で確認してください。');

        $spot = $this->spots->find($id);
        return Response::redirect($this->urls->to('admin/spots/' . $spot['management_token'] . '/qr'));
    }

    public function editForm(int $id, array $errors = [], ?SpotInput $input = null, int $status = 200): Response
    {
        if (($response = $this->requireAdmin()) !== null) {
            return $response;
        }
        $spot = $this->spots->find($id);
        if ($spot === null) {
            return $this->notFound();
        }

        return $this->view('admin/spots/form.php', [
            'title' => 'スポット編集',
            'spot' => $spot,
            'values' => [
                'name' => $input?->name ?? $spot['name'],
                'description' => $input?->description ?? $spot['description'],
            ],
            'errors' => $errors,
        ], $status);
    }

    public function update(Request $request, int $id): Response
    {
        if (($response = $this->guardPost($request)) !== null) {
            return $response;
        }
        if ($this->spots->find($id) === null) {
            return $this->notFound();
        }
        $input = new SpotInput($request->input('name'), $request->input('description'));
        $errors = $this->validator->validate($input);
        if ($errors !== []) {
            return $this->editForm($id, $errors, $input, 422);
        }
        $this->spots->update($id, $input);
        $this->logs->record('spot.updated', 'admin', $this->auth->id(), 'success', [], 'spot', $id);
        $this->flash->set('success', 'スポットを更新しました。');

        return Response::redirect($this->urls->to('admin/spots'));
    }

    public function toggle(Request $request, int $id): Response
    {
        if (($response = $this->guardPost($request)) !== null) {
            return $response;
        }
        $spot = $this->spots->find($id);
        if ($spot === null) {
            return $this->notFound();
        }
        $active = !(bool) $spot['is_active'];
        $this->spots->setActive($id, $active);
        $this->logs->record('spot.' . ($active ? 'resumed' : 'stopped'), 'admin', $this->auth->id(), 'success', [], 'spot', $id);
        $this->flash->set('success', $active ? 'スポットを再開しました。' : 'スポットを停止しました。過去の取得実績は維持されます。');

        return Response::redirect($this->urls->to('admin/spots'));
    }

    public function move(Request $request, int $id): Response
    {
        if (($response = $this->guardPost($request)) !== null) {
            return $response;
        }
        try {
            $direction = $request->input('direction');
            $this->spots->move($id, $direction);
            $this->logs->record('spot.reordered', 'admin', $this->auth->id(), 'success', ['direction' => $direction], 'spot', $id);
        } catch (RuntimeException $error) {
            $this->flash->set('error', $error->getMessage());
        }

        return Response::redirect($this->urls->to('admin/spots'));
    }

    public function delete(Request $request, int $id): Response
    {
        if (($response = $this->guardPost($request)) !== null) {
            return $response;
        }
        $event = $this->events->find();
        if ($event === null || new DateTimeImmutable('now', new DateTimeZone('UTC')) >= new DateTimeImmutable((string) $event['starts_at'])) {
            $this->flash->set('error', 'スポットを削除できるのは開催前だけです。停止を使用してください。');
            return Response::redirect($this->urls->to('admin/spots'));
        }
        try {
            $this->spots->delete($id);
            $this->logs->record('spot.deleted', 'admin', $this->auth->id(), 'success', [], 'spot', $id);
            $remainingCount = $this->spots->count();
            if ($remainingCount < (int) $event['required_stamp_count']) {
                $this->flash->set(
                    'error',
                    "スポットを削除しました。残り{$remainingCount}件となり、達成条件を満たせません。イベント設定を見直してください。",
                );
            } else {
                $this->flash->set('success', 'スポットを削除しました。');
            }
        } catch (RuntimeException $error) {
            $this->flash->set('error', $error->getMessage());
        }

        return Response::redirect($this->urls->to('admin/spots'));
    }

    public function reissue(Request $request, int $id): Response
    {
        if (($response = $this->guardPost($request)) !== null) {
            return $response;
        }
        try {
            $this->spots->reissueToken($id);
            $this->logs->record('spot.qr_reissued', 'admin', $this->auth->id(), 'success', [], 'spot', $id);
            $this->flash->set('success', 'QRコードを再発行しました。古いQRコードは無効です。');
        } catch (RuntimeException $error) {
            $this->flash->set('error', $error->getMessage());
        }

        $spot = $this->spots->find($id);
        return Response::redirect($this->urls->to('admin/spots/' . $spot['management_token'] . '/qr'));
    }

    public function qr(int $id): Response
    {
        if (($response = $this->requireAdmin()) !== null) {
            return $response;
        }
        $spot = $this->spots->find($id);
        if ($spot === null) {
            return $this->notFound();
        }
        $publicUrl = $this->publicUrl($spot);

        return $this->view('admin/spots/qr.php', [
            'title' => 'QRコード確認',
            'spot' => $spot,
            'publicUrl' => $publicUrl,
            'qrDataUri' => 'data:image/svg+xml;base64,' . base64_encode($this->qrCodes->svg($publicUrl)),
        ]);
    }

    public function downloadQr(int $id): Response
    {
        if (($response = $this->requireAdmin()) !== null) {
            return $response;
        }
        $spot = $this->spots->find($id);
        if ($spot === null) {
            return $this->notFound();
        }
        $filename = $this->filenames->spotQrSvg(
            (int) $spot['display_order'],
            (string) $spot['name'],
        );
        $asciiFilename = sprintf('spot-%02d-qr.svg', (int) $spot['display_order']);

        return new Response($this->qrCodes->svg($this->publicUrl($spot)), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $asciiFilename
                . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function publicPreview(string $token): Response
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $token)) {
            return $this->notFound();
        }
        $spot = $this->spots->findByPublicToken($token);
        if ($spot === null) {
            return $this->notFound();
        }

        return new Response($this->templates->render('spot-preview.php', [
            'spot' => $spot,
            'assetUrl' => $this->urls->to('assets/app.css'),
        ]));
    }

    public function resolveManagementId(string $token): ?int
    {
        return $this->spots->findIdByManagementToken($token);
    }

    /** @param array<string, mixed> $spot */
    private function publicUrl(array $spot): string
    {
        return $this->urls->to('spot/' . $this->spots->publicToken($spot));
    }

    private function guardPost(Request $request): ?Response
    {
        if (($response = $this->requireAdmin()) !== null) {
            return $response;
        }

        return !$this->csrf->verify($request->input('_csrf'))
            ? $this->view('errors/419.php', ['title' => '操作を続けられません'], 419)
            : null;
    }

    private function requireAdmin(): ?Response
    {
        return $this->auth->id() === null
            ? Response::redirect($this->urls->to('admin/login'), 302)
            : null;
    }

    private function notFound(): Response
    {
        return new Response($this->templates->render('errors/404.php'), 404);
    }

    /** @param array<string, mixed> $data */
    private function view(string $template, array $data, int $status = 200): Response
    {
        $data = array_merge($this->views->common(), $data);

        return new Response($this->templates->renderWithLayout($template, $data), $status);
    }
}
