<?php

declare(strict_types=1);

namespace QrRally\Http;

use QrRally\Controller\AdminController;
use QrRally\Controller\SpotController;
use QrRally\Controller\ParticipantController;
use QrRally\Controller\ApplicationController;
use QrRally\Controller\PrintController;
use QrRally\Exception\DatabaseBusyException;
use QrRally\Security\TrafficMonitor;
use QrRally\View\TemplateRenderer;

final class Application
{
    public function __construct(
        private readonly AdminController $admin,
        private readonly SpotController $spots,
        private readonly ParticipantController $participants,
        private readonly ApplicationController $applications,
        private readonly PrintController $prints,
        private readonly TemplateRenderer $templates,
        private readonly string $baseUrl,
        private readonly TrafficMonitor $traffic,
    ) {
    }

    public function handle(Request $request): Response
    {
        $path = $request->path($this->baseUrl);
        $isAdminPath = $path === '/admin' || str_starts_with($path, '/admin/');
        if (!$isAdminPath && !$this->traffic->allowRequest($request->clientIp())) {
            return new Response($this->templates->render('errors/429.php'), 429, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store',
                'Retry-After' => '60',
            ]);
        }

        try {
            return $this->dispatch($request, $path);
        } catch (DatabaseBusyException) {
            return new Response($this->templates->render('errors/503.php'), 503, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store',
                'Retry-After' => '1',
            ]);
        }
    }

    private function dispatch(Request $request, string $path): Response
    {
        $route = $request->method() . ' ' . $path;

        $static = match ($route) {
            'GET /' => $this->participants->home($request),
            'POST /join' => $this->participants->join($request),
            'GET /notices' => $this->participants->notices(),
            'GET /application' => $this->applications->form($request),
            'POST /application/confirm' => $this->applications->confirm($request),
            'POST /application/submit' => $this->applications->submit($request),
            'GET /application/complete' => $this->applications->complete($request),
            'GET /admin/login' => $this->admin->loginForm(),
            'POST /admin/login' => $this->admin->login($request),
            'POST /admin/logout' => $this->admin->logout($request),
            'GET /admin/password/reset' => $this->admin->resetForm(),
            'POST /admin/password/reset' => $this->admin->reset($request),
            'GET /admin', 'GET /admin/' => $this->admin->dashboard(),
            'GET /admin/event' => $this->admin->eventForm(),
            'POST /admin/event' => $this->admin->saveEvent($request),
            'GET /admin/applications/settings' => $this->applications->settings(),
            'POST /admin/applications/settings' => $this->applications->saveSettings($request),
            'GET /admin/applications' => $this->applications->report(),
            'GET /admin/applications.csv' => $this->applications->csv(),
            'GET /admin/applications/applicants.csv' => $this->applications->applicationsCsv(),
            'GET /admin/logs' => $this->admin->logs(),
            'GET /admin/spots' => $this->spots->index(),
            'GET /admin/spots/create' => $this->spots->createForm(),
            'POST /admin/spots' => $this->spots->create($request),
            'GET /admin/print/entrance' => $this->prints->entrance(),
            'GET /admin/print/spots' => $this->prints->spots(),
            'GET /admin/print/spots.pdf' => $this->prints->spotsPdf(),
            default => null,
        };
        if ($static !== null) {
            return $static;
        }

        if (preg_match('#^/admin/spots/([0-9a-f-]{36})/(edit|toggle|move|delete|reissue|qr|qr\.svg|print)$#D', $path, $matches)) {
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
                'GET print' => $this->prints->spot($id),
                default => new Response($this->templates->render('errors/404.php'), 404),
            };
        }
        if ($request->method() === 'GET' && preg_match('#^/spot/([^/]+)$#D', $path, $matches)) {
            return $this->participants->spot($request, $matches[1]);
        }

        return new Response($this->templates->render('errors/404.php'), 404);
    }
}
