<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QrRally\View\TemplateRenderer;

final class SecurityRequirementsTest extends TestCase
{
    public function testPrintTemplateEscapesEventAndSpotNames(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $attack = '<script>alert("xss")</script>';
        $html = $renderer->render('print/spot.php', [
            'event' => ['name' => $attack],
            'spot' => [
                'name' => $attack,
                'description' => $attack,
                'display_order' => 1,
                'qr_data_uri' => 'data:image/svg+xml;base64,PHN2Zy8+',
            ],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertSame(4, substr_count($html, '&lt;script&gt;'));
    }

    public function testEmergencyCredentialCommandIsCliOnlyAndDoesNotAcceptArgvSecrets(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/reset-admin-credentials.php');
        $guard = strpos($source, "PHP_SAPI !== 'cli'");
        $bootstrap = strpos($source, "'/bootstrap.php'");

        self::assertNotFalse($guard);
        self::assertNotFalse($bootstrap);
        self::assertLessThan($bootstrap, $guard);
        self::assertStringNotContainsString('$argv', $source);
        self::assertStringContainsString("->hidden('新しいパスワード", $source);
    }

    public function testBusyAndRateLimitPagesGiveJapaneseRetryGuidance(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');

        self::assertStringContainsString('少し時間をおいて再度お試しください', $renderer->render('errors/429.php'));
        self::assertStringContainsString('もう一度QRコードを読み取ってください', $renderer->render('errors/503.php'));
    }
}
