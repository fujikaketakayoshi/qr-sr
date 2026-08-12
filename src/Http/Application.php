<?php

declare(strict_types=1);

namespace QrRally\Http;

use QrRally\Controller\AdminController;
use QrRally\Controller\SpotController;
use QrRally\View\TemplateRenderer;

final class Application
{
    public function __construct(
        private readonly AdminController $admin,
        private readonly SpotController $spots,
        private readonly TemplateRenderer $templates,
        private readonly string $baseUrl,
    ) {
    }

    public function handle(Request $request): Response
    {
        $route = $request->method() . ' ' . $request->path($this->baseUrl);

        $static = match ($route) {
            'GET /admin/login' => $this->admin->loginForm(),
            'POST /admin/login' => $this->admin->login($request),
            'POST /admin/logout' => $this->admin->logout($request),
            'GET /admin/password/reset' => $this->admin->resetForm(),
            'POST /admin/password/reset' => $this->admin->reset($request),
            'GET /admin', 'GET /admin/' => $this->admin->dashboard(),
            'GET /admin/event' => $this->admin->eventForm(),
            'POST /admin/event' => $this->admin->saveEvent($request),
            'GET /admin/logs' => $this->admin->logs(),
            'GET /admin/spots' => $this->spots->index(),
            'GET /admin/spots/create' => $this->spots->createForm(),
            'POST /admin/spots' => $this->spots->create($request),
            default => null,
        };
        if ($static !== null) {
            return $static;
        }

        $path = $request->path($this->baseUrl);
        if (preg_match('#^/admin/spots/([0-9a-f-]{36})/(edit|toggle|move|delete|reissue|qr|qr\.svg)$#D', $path, $matches)) {
            $id = $this->spots->resolveManagementId($matches[1]);
            if ($id === null) {
                return new Response($this->templates->render('errors/404.php'), 404);
            }
            return match ($request->method() . ' ' . $matches[2]) {
                'GET edit' => $this->spots->editForm($id),
                'POST edit' => $this->spots->update($request, $id),
                'POST toggle' => $this->spots->toggle($request, $id),
                'POST move' => $this->spots->move($request, $id),
                'POST delete' => $this->spots->delete($request, $id),
                'POST reissue' => $this->spots->reissue($request, $id),
                'GET qr' => $this->spots->qr($id),
                'GET qr.svg' => $this->spots->downloadQr($id),
                default => new Response($this->templates->render('errors/404.php'), 404),
            };
        }
        if ($request->method() === 'GET' && preg_match('#^/spot/([^/]+)$#D', $path, $matches)) {
            return $this->spots->publicPreview($matches[1]);
        }

        return new Response($this->templates->render('errors/404.php'), 404);
    }
}
