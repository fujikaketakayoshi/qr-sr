<?php

declare(strict_types=1);

namespace QrRally\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use QrRally\Auth\AdminAuth;
use QrRally\Controller\SpotController;
use QrRally\Database\{ConnectionFactory, Migrator};
use QrRally\Domain\{EventInput, SpotInput, SpotValidator};
use QrRally\Http\Request;
use QrRally\Repository\{AdminRepository, AuditLogRepository, EventRepository, LoginAttemptRepository, SpotRepository};
use QrRally\Security\{CsrfToken, SpotToken};
use QrRally\Session\{Flash, SessionManager};
use QrRally\Support\{DemoMode, DownloadFilename, QrCodeGenerator, UrlGenerator, ViewData};
use QrRally\View\TemplateRenderer;

final class SpotPresentationTest extends TestCase
{
    private string $directory;
    private PDO $database;
    private SpotRepository $spots;
    private SpotController $controller;
    private CsrfToken $csrf;
    private string $token;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->directory = sys_get_temp_dir() . '/qr-presentation-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->database = (new ConnectionFactory())->connect($this->directory . '/app.sqlite');
        (new Migrator($this->database, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $events = new EventRepository($this->database);
        $events->save(new EventInput('試験イベント', '', '', '', '', false, '', 1, ''), '2000-01-01T00:00:00Z', '2100-01-01T00:00:00Z');
        $this->spots = new SpotRepository($this->database, new SpotToken(str_repeat('a', 64)));
        $id = $this->spots->create(new SpotInput('町の電気屋さん', '非表示の説明'));
        $this->token = $this->spots->publicToken($this->spots->find($id));
        $urls = new UrlGenerator('https://example.test/rally/');
        $this->csrf = new CsrfToken();
        $flash = new Flash();
        $logs = new AuditLogRepository($this->database);
        $demo = new DemoMode(false, 'Asia/Tokyo');
        $auth = new AdminAuth(new AdminRepository($this->database), new LoginAttemptRepository($this->database), $logs, new SessionManager(), $this->csrf, str_repeat('b', 64), 7200);
        $this->controller = new SpotController(
            new TemplateRenderer(dirname(__DIR__, 2) . '/templates'),
            new ViewData($urls, $this->csrf, $flash, $demo), $urls, $this->csrf, $flash, $auth,
            $this->spots, $events, $logs, new SpotValidator(), new QrCodeGenerator(), new DownloadFilename(), $demo,
        );
    }

    protected function tearDown(): void
    {
        unset($this->controller, $this->spots, $this->database);
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        $_SESSION = [];
    }

    private function signIn(): void
    {
        $id = (new AdminRepository($this->database))->create('admin@example.test', 'test-password-hash', 'test-recovery-hash');
        $_SESSION['admin_id'] = $id;
        $_SESSION['auth_version'] = 1;
        $_SESSION['last_activity_at'] = time();
    }

    public function testPublicPresentationShowsAcquisitionQrWithoutRegistrationOrAdminInformation(): void
    {
        $response = $this->controller->presentation($this->token);
        self::assertSame(200, $response->status());
        self::assertStringContainsString('試験イベント', $response->body());
        self::assertStringContainsString('町の電気屋さん', $response->body());
        $qr = 'data:image/svg+xml;base64,' . base64_encode((new QrCodeGenerator())->svg('https://example.test/rally/spot/' . $this->token));
        self::assertStringContainsString($qr, $response->body());
        self::assertStringContainsString('https://example.test/rally/assets/app.css', $response->body());
        self::assertStringNotContainsString('/admin/', $response->body());
        self::assertStringNotContainsString('非表示の説明', $response->body());
        self::assertStringNotContainsString('name="nickname"', $response->body());
        $headers = (new \ReflectionProperty(\QrRally\Http\Response::class, 'headers'))->getValue($response);
        self::assertArrayNotHasKey('Set-Cookie', $headers);
        self::assertSame('private, no-store', $headers['Cache-Control']);
        self::assertSame('noindex, nofollow', $headers['X-Robots-Tag']);
        self::assertSame('no-referrer', $headers['Referrer-Policy']);
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM participants')->fetchColumn());
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM stamp_acquisitions')->fetchColumn());
        self::assertSame(302, $this->controller->qr(1)->status());
        self::assertSame(302, $this->controller->downloadQr(1)->status());
    }

    public function testPresentationAvailableWhenSpotStoppedAndEventOutsidePeriodOrPaused(): void
    {
        $this->spots->setActive(1, false);
        foreach (["is_paused = 1", "is_paused = 0, starts_at = '2100-01-01T00:00:00Z', ends_at = '2101-01-01T00:00:00Z'", "starts_at = '2000-01-01T00:00:00Z', ends_at = '2001-01-01T00:00:00Z'"] as $update) {
            $this->database->exec('UPDATE events SET ' . $update);
            self::assertSame(200, $this->controller->presentation($this->token)->status());
        }
    }

    public function testReissueInvalidatesOldPresentationAndAcquisitionTokens(): void
    {
        $this->signIn();
        $response = $this->controller->reissue(new Request([], [], ['_csrf' => $this->csrf->get()]), 1);
        self::assertSame(303, $response->status());
        self::assertSame(404, $this->controller->presentation($this->token)->status());
        self::assertNull($this->spots->findByPublicToken($this->token));
        $new = $this->spots->publicToken($this->spots->find(1));
        self::assertNotSame($this->token, $new);
        self::assertSame(200, $this->controller->presentation($new)->status());
        self::assertNotNull($this->spots->findByPublicToken($new));
    }

    public function testAdminPageLinksToPresentationAndWarnsInBothPlaces(): void
    {
        $this->signIn();
        $body = $this->controller->qr(1)->body();
        self::assertStringContainsString('提示用QRコードを表示', $body);
        self::assertStringContainsString('https://example.test/rally/spot/' . $this->token . '/qr', $body);
        self::assertStringContainsString('ログイン不要', $body);
        self::assertSame(2, substr_count($body, '担当者へ共有した提示用URLも無効になります。'));
        self::assertStringContainsString('onsubmit="return confirm(', $body);
        self::assertSame(419, $this->controller->reissue(new Request([], [], ['_csrf' => 'invalid']), 1)->status());
        self::assertSame(200, $this->controller->presentation($this->token)->status());
    }

    public function testInvalidDeletedOrUnconfiguredPresentationReturnsNotFound(): void
    {
        self::assertSame(404, $this->controller->presentation('invalid')->status());
        $managementToken = $this->spots->find(1)['management_token'];
        self::assertSame(404, $this->controller->presentation($managementToken)->status());
        $this->database->exec('DELETE FROM events');
        self::assertSame(404, $this->controller->presentation($this->token)->status());
        $this->spots->delete(1);
        self::assertSame(404, $this->controller->presentation($this->token)->status());
    }

    public function testEventAndSpotNamesAreEscaped(): void
    {
        $this->database->exec("UPDATE events SET name = '<script>alert(1)</script>'");
        $this->spots->update(1, new SpotInput('<img src=x onerror=alert(1)>', ''));
        $body = $this->controller->presentation($this->token)->body();
        self::assertStringNotContainsString('<script>', $body);
        self::assertStringNotContainsString('<img src=x', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
        self::assertStringContainsString('&lt;img src=x', $body);
    }
}
