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

        $cacheDirectory = sys_get_temp_dir() . '/qr-rally-pdf-test-' . bin2hex(random_bytes(8));

        try {
            $pdf = (new PrintPdfGenerator($projectDirectory, $cacheDirectory))->render($html);

            self::assertDirectoryExists($cacheDirectory);
        } finally {
            if (is_dir($cacheDirectory)) {
                foreach (scandir($cacheDirectory) ?: [] as $file) {
                    if ($file !== '.' && $file !== '..') {
                        unlink($cacheDirectory . '/' . $file);
                    }
                }
                rmdir($cacheDirectory);
            }
        }

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(10_000, strlen($pdf));
    }
}
