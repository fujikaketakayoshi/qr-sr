<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QrRally\View\TemplateRenderer;

final class TemplateRendererTest extends TestCase
{
    public function testEscapedHomeTemplateOutput(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');

        $html = $renderer->render('home.php', [
            'assetUrl' => 'http://example.test/assets/app.css',
            'environment' => '<production>',
            'migrationCount' => 1,
            'adminUrl' => 'http://example.test/admin/',
        ]);

        self::assertStringContainsString('&lt;production&gt;', $html);
        self::assertStringNotContainsString('<production>', $html);
    }
}
