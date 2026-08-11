<?php

declare(strict_types=1);

namespace QrRally\Http;

use QrRally\Controller\AdminController;
use QrRally\View\TemplateRenderer;

final class Application
{
    public function __construct(
        private readonly AdminController $admin,
        private readonly TemplateRenderer $templates,
        private readonly string $baseUrl,
    ) {
    }

    public function handle(Request $request): Response
    {
        $route = $request->method() . ' ' . $request->path($this->baseUrl);

        return match ($route) {
            'GET /admin/login' => $this->admin->loginForm(),
            'POST /admin/login' => $this->admin->login($request),
            'POST /admin/logout' => $this->admin->logout($request),
            'GET /admin/password/reset' => $this->admin->resetForm(),
            'POST /admin/password/reset' => $this->admin->reset($request),
            'GET /admin', 'GET /admin/' => $this->admin->dashboard(),
            'GET /admin/event' => $this->admin->eventForm(),
            'POST /admin/event' => $this->admin->saveEvent($request),
            'GET /admin/logs' => $this->admin->logs(),
            default => new Response($this->templates->render('errors/404.php'), 404),
        };
    }
}
