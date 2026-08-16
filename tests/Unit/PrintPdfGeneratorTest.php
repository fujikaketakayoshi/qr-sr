<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QrRally\Support\PrintPdfGenerator;
use QrRally\Support\QrCodeGenerator;
use QrRally\View\TemplateRenderer;

final class PrintPdfGeneratorTest extends TestCase
{
    public function testGeneratesJapaneseA4PdfWithQrCode(): void
    {
        $projectDirectory = dirname(__DIR__, 2);
        $qr = (new QrCodeGenerator())->svg('http://example.test/spot/123e4567-e89b-42d3-a456-426614174000');
        $html = (new TemplateRenderer($projectDirectory . '/templates'))->render('print/spots-pdf.php', [
            'event' => ['name' => str_repeat('あ', 50)],
            'spots' => [[
                'name' => str_repeat('い', 50),
                'display_order' => 1,
                'qr_data_uri' => 'data:image/svg+xml;base64,' . base64_encode($qr),
            ]],
            'fontDirectory' => $projectDirectory . '/public/assets/fonts',
        ]);

        $pdf = (new PrintPdfGenerator($projectDirectory))->render($html);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(10_000, strlen($pdf));
    }
}
