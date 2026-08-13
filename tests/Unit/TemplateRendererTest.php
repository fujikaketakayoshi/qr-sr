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

    public function testApplicationSettingsShowsErrorSummaryWhenValidationFails(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');

        $html = $renderer->render('admin/applications/settings.php', [
            'event' => [
                'application_enabled' => 1,
                'application_deadline_at' => null,
                'privacy_purpose_text' => '',
                'ends_at' => '2026-08-13T12:00:00Z',
            ],
            'fields' => [],
            'errors' => ['fields' => '応募に使用する項目を1つ以上選択してください。'],
            'submitted' => [
                'application_enabled' => true,
                'application_deadline_at' => '',
                'privacy_purpose_text' => '',
                'fields' => [],
            ],
            'timezone' => 'Asia/Tokyo',
            'defaultPurpose' => '既定の利用目的',
            'csrfToken' => 'test-token',
        ]);

        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('入力内容にエラーがあります。', $html);
        self::assertStringContainsString('応募に使用する項目を1つ以上選択してください。', $html);
        self::assertStringContainsString('イベント終了日時', $html);
        self::assertStringContainsString('2026年8月13日 21:00', $html);
    }
}
