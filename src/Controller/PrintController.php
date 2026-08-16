<?php

declare(strict_types=1);

namespace QrRally\Controller;

use DateTimeImmutable;
use DateTimeZone;
use QrRally\Auth\AdminAuth;
use QrRally\Http\Response;
use QrRally\Repository\AuditLogRepository;
use QrRally\Repository\EventRepository;
use QrRally\Repository\SpotRepository;
use QrRally\Support\PrintPdfGenerator;
use QrRally\Support\QrCodeGenerator;
use QrRally\Support\UrlGenerator;
use QrRally\Support\ViewData;
use QrRally\View\TemplateRenderer;
use Throwable;

final class PrintController
{
    public function __construct(
        private readonly TemplateRenderer $templates,
        private readonly ViewData $views,
        private readonly UrlGenerator $urls,
        private readonly AdminAuth $auth,
        private readonly EventRepository $events,
        private readonly SpotRepository $spots,
        private readonly AuditLogRepository $logs,
        private readonly QrCodeGenerator $qrCodes,
        private readonly PrintPdfGenerator $pdfs,
        private readonly string $fontDirectory,
        private readonly string $timezone,
    ) {
    }

    public function entrance(): Response
    {
        if (($response = $this->requireAdmin()) !== null) return $response;
        $event = $this->events->find();
        if ($event === null) return $this->missingEvent();

        return $this->printView('print/entrance.php', [
            'title' => 'イベント入口用案内',
            'event' => $event,
            'entryUrl' => $this->urls->to(''),
            'qrDataUri' => $this->dataUri($this->urls->to('')),
            'startsAt' => $this->displayDate((string) $event['starts_at']),
            'endsAt' => $this->displayDate((string) $event['ends_at']),
        ]);
    }

    public function spot(int $id): Response
    {
        if (($response = $this->requireAdmin()) !== null) return $response;
        $event = $this->events->find();
        $spot = $this->spots->find($id);
        if ($event === null) return $this->missingEvent();
        if ($spot === null) return $this->notFound();

        return $this->printView('print/spot.php', [
            'title' => (string) $spot['name'] . ' 印刷用QR',
            'event' => $event,
            'spot' => $this->printableSpot($spot),
        ]);
    }

    public function spots(): Response
    {
        if (($response = $this->requireAdmin()) !== null) return $response;
        $event = $this->events->find();
        if ($event === null) return $this->missingEvent();

        return $this->printView('print/spots.php', [
            'title' => '全スポット印刷',
            'event' => $event,
            'spots' => array_map($this->printableSpot(...), $this->spots->all()),
        ]);
    }

    public function spotsPdf(): Response
    {
        if (($response = $this->requireAdmin()) !== null) return $response;
        $event = $this->events->find();
        if ($event === null) return $this->missingEvent();
        $spots = array_map($this->printableSpot(...), $this->spots->all());

        try {
            $html = $this->templates->render('print/spots-pdf.php', [
                'event' => $event,
                'spots' => $spots,
                'fontDirectory' => $this->fontDirectory,
            ]);
            $pdf = $this->pdfs->render($html);
        } catch (Throwable $error) {
            return new Response($this->templates->renderWithLayout('admin/print-error.php', array_merge(
                $this->views->common(),
                ['title' => 'PDF生成エラー'],
            )), 500);
        }

        $this->logs->record('spots.pdf_exported', 'admin', $this->auth->id(), 'success');

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="qr-rally-spots.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array<string,mixed> $spot @return array<string,mixed> */
    private function printableSpot(array $spot): array
    {
        $publicUrl = $this->urls->to('spot/' . $this->spots->publicToken($spot));
        $spot['qr_data_uri'] = $this->dataUri($publicUrl);
        return $spot;
    }

    private function dataUri(string $url): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($this->qrCodes->svg($url));
    }

    private function displayDate(string $date): string
    {
        return (new DateTimeImmutable($date))
            ->setTimezone(new DateTimeZone($this->timezone))
            ->format('Y年n月j日 H:i');
    }

    /** @param array<string,mixed> $data */
    private function printView(string $template, array $data): Response
    {
        return new Response($this->templates->renderWithLayout($template, array_merge(
            $this->views->common(),
            $data,
        ), 'print/layout.php'), 200, ['Cache-Control' => 'private, no-store']);
    }

    private function requireAdmin(): ?Response
    {
        return $this->auth->id() === null ? Response::redirect($this->urls->to('admin/login'), 302) : null;
    }

    private function missingEvent(): Response
    {
        return Response::redirect($this->urls->to('admin/event'));
    }

    private function notFound(): Response
    {
        return new Response($this->templates->render('errors/404.php'), 404);
    }
}
