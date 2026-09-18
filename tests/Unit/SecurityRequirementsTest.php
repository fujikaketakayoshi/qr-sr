<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QrRally\Domain\EventStatus;
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

    public function testParticipantAndAdminTemplatesEscapeStoredAttackPayloads(): void
    {
        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $attack = '"><img src=x onerror=alert(1)><script>alert(2)</script>';
        $url = static fn (string $path = ''): string => 'https://example.test/' . $path;

        $participantHtml = $renderer->render('participant/home.php', [
            'event' => [
                'name' => $attack,
                'description' => $attack,
                'pause_message' => $attack,
                'completion_message' => $attack,
                'required_stamp_count' => 1,
            ],
            'participant' => ['nickname' => $attack],
            'spots' => [[
                'name' => $attack,
                'display_order' => 1,
                'is_active' => 1,
                'acquired_at' => null,
            ]],
            'acquisitionCount' => 0,
            'completed' => false,
            'showApplicationLink' => false,
            'application' => null,
            'applicationEditable' => false,
            'applicationDeadlinePassed' => false,
            'eventStatus' => EventStatus::Active,
            'displayStartsAt' => $attack,
            'displayEndsAt' => $attack,
            'displayApplicationDeadline' => $attack,
            'url' => $url,
        ]);
        $adminHtml = $renderer->render('admin/logs.php', [
            'logs' => [[
                'created_at' => $attack,
                'event_type' => $attack,
                'result' => $attack,
                'actor_type' => $attack,
                'context_json' => $attack,
            ]],
        ]);

        foreach ([$participantHtml, $adminHtml] as $html) {
            self::assertStringNotContainsString('<script>', $html);
            self::assertStringNotContainsString('<img ', $html);
            self::assertStringNotContainsString('<img src=x onerror=', $html);
            self::assertStringContainsString('&lt;script&gt;', $html);
        }
    }

    public function testEveryStateChangingControllerActionContainsCsrfGuard(): void
    {
        $root = dirname(__DIR__, 2);
        $requirements = [
            'AdminController.php' => ['login', 'logout', 'reset', 'saveEvent'],
            'ApplicationController.php' => ['saveSettings', 'confirm', 'submit'],
            'BackupController.php' => ['download', 'upload', 'restore'],
            'InstallController.php' => ['handle'],
            'ParticipantController.php' => ['join'],
            'SpotController.php' => ['create', 'update', 'toggle', 'move', 'delete', 'reissue'],
        ];

        foreach ($requirements as $file => $methods) {
            $source = (string) file_get_contents($root . '/src/Controller/' . $file);
            foreach ($methods as $method) {
                $start = strpos($source, 'public function ' . $method . '(');
                self::assertNotFalse($start, "{$file}::{$method} must exist");
                $nextPublic = strpos($source, '\n    public function ', $start + 1);
                $nextPrivate = strpos($source, '\n    private function ', $start + 1);
                $ends = array_filter([$nextPublic, $nextPrivate], static fn (int|false $position): bool => $position !== false);
                $end = $ends === [] ? strlen($source) : min($ends);
                $body = substr($source, $start, $end - $start);
                self::assertMatchesRegularExpression(
                    '/(?:csrf->verify|guardAdminPost|guardPost)\\(/',
                    $body,
                    "{$file}::{$method} must verify a CSRF token",
                );
            }
        }
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
