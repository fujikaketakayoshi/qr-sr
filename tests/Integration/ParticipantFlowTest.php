<?php

declare(strict_types=1);

namespace QrRally\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use QrRally\Controller\ParticipantController;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;
use QrRally\Domain\EventInput;
use QrRally\Domain\SpotInput;
use QrRally\Http\Request;
use QrRally\Http\Response;
use QrRally\Repository\{ApplicationRepository, AuditLogRepository, EventRepository, ParticipantRepository, SpotRepository};
use QrRally\Security\{CsrfToken, ParticipantCookie, ParticipantToken, SpotToken, TrafficMonitor};
use QrRally\Session\Flash;
use QrRally\Support\{DemoMode, UrlGenerator, ViewData};
use QrRally\View\TemplateRenderer;

final class ParticipantFlowTest extends TestCase
{
    private string $directory;
    private PDO $database;
    private ParticipantController $controller;
    private ParticipantRepository $participants;
    private SpotRepository $spots;
    private CsrfToken $csrf;
    private string $spotToken;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->directory = sys_get_temp_dir() . '/qr-flow-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->database = (new ConnectionFactory())->connect($this->directory . '/app.sqlite');
        (new Migrator($this->database, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $events = new EventRepository($this->database);
        $events->save(new EventInput('試験イベント', '', '主催者からの注意', '', '', false, '', 2, '達成です'), '2000-01-01T00:00:00Z', '2100-01-01T00:00:00Z');
        $this->spots = new SpotRepository($this->database, new SpotToken(str_repeat('a', 64)));
        $id = $this->spots->create(new SpotInput('スポット1', ''));
        $this->spotToken = $this->spots->publicToken($this->spots->find($id));
        $tokens = new ParticipantToken();
        $this->participants = new ParticipantRepository($this->database, $tokens);
        $this->csrf = new CsrfToken();
        $urls = new UrlGenerator('https://example.test/rally/');
        $this->controller = new ParticipantController(
            new TemplateRenderer(dirname(__DIR__, 2) . '/templates'),
            new ViewData($urls, $this->csrf, new Flash(), new DemoMode(false, 'Asia/Tokyo')),
            $urls, $this->csrf, $events, $this->spots, $this->participants,
            new ApplicationRepository($this->database), $tokens, new AuditLogRepository($this->database),
            str_repeat('b', 64), 'Asia/Tokyo', new ParticipantCookie(true, '/rally/'),
            new TrafficMonitor($this->directory . '/traffic.sqlite', str_repeat('b', 64)),
        );
    }

    protected function tearDown(): void
    {
        unset($this->controller, $this->participants, $this->spots, $this->database);
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        $_SESSION = [];
    }

    private function cookie(Response $response): string
    {
        $headers = (new \ReflectionProperty(Response::class, 'headers'))->getValue($response);
        self::assertStringContainsString('Path=/rally/; Max-Age=', $headers['Set-Cookie']);
        self::assertStringContainsString('HttpOnly; SameSite=Lax; Secure', $headers['Set-Cookie']);
        self::assertSame('private, no-store', $headers['Cache-Control']);
        preg_match('/qr_rally_participant=([^;]+)/', $headers['Set-Cookie'], $matches);
        return $matches[1];
    }

    private function request(string $token, array $post = []): Request
    {
        return new Request(['REMOTE_ADDR' => '192.0.2.1'], [], $post, ['qr_rally_participant' => $token]);
    }

    public function testEntranceShowsBoardWithoutStampOrRequiredNickname(): void
    {
        $response = $this->controller->home(new Request([]));
        $token = $this->cookie($response);
        self::assertSame(200, $response->status());
        self::assertStringContainsString('スタンプ台紙', $response->body());
        self::assertStringContainsString('ニックネーム（任意）', $response->body());
        self::assertStringNotContainsString('notice_accepted', $response->body());
        self::assertStringNotContainsString('コードスキャナー', $response->body());
        self::assertSame('', $this->participants->findByToken($token)['nickname']);
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM stamp_acquisitions')->fetchColumn());
        $this->controller->home($this->request($token));
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM participants')->fetchColumn());
        self::assertStringContainsString('主催者からの注意', $this->controller->notices()->body());
        self::assertStringContainsString('コードスキャナー', $this->controller->notices()->body());
    }

    public function testFirstSpotThenSecondSpotCompletesWithoutNicknameAndAvoidsDuplicates(): void
    {
        $response = $this->controller->spot(new Request([]), $this->spotToken);
        $token = $this->cookie($response);
        $id = (int) $this->participants->findByToken($token)['id'];
        self::assertStringContainsString('スタンプを取得しました', $response->body());
        self::assertSame(1, $this->participants->acquisitionCount($id));
        self::assertStringNotContainsString('name="nickname"', $response->body());
        self::assertStringContainsString('取得済み', $this->controller->spot($this->request($token), $this->spotToken)->body());
        $second = $this->spots->create(new SpotInput('スポット2', ''));
        $this->database->exec('UPDATE events SET application_enabled = 1');
        $result = $this->controller->spot($this->request($token), $this->spots->publicToken($this->spots->find($second)));
        self::assertStringContainsString('達成おめでとうございます', $result->body());
        self::assertStringContainsString('応募フォームを開く', $result->body());
        self::assertSame(2, $this->participants->acquisitionCount($id));
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM participants')->fetchColumn());
        self::assertStringContainsString('2 / 2', strip_tags($this->controller->home($this->request($token))->body()));
    }

    public function testNicknameRegistrationIsOptionalAndCannotOverwriteRegisteredName(): void
    {
        $token = $this->cookie($this->controller->spot(new Request([]), $this->spotToken));
        $id = (int) $this->participants->findByToken($token)['id'];
        self::assertSame(419, $this->controller->join($this->request($token, ['nickname' => '変更']))->status());
        $post = ['_csrf' => $this->csrf->get(), 'nickname' => str_repeat('あ', 51)];
        self::assertSame(422, $this->controller->join($this->request($token, $post))->status());
        self::assertSame('', $this->participants->findByToken($token)['nickname']);
        $post['nickname'] = '';
        self::assertSame(303, $this->controller->join($this->request($token, $post))->status());
        self::assertSame('未設定', (new ApplicationRepository($this->database))->exportRows()[0]['nickname']);
        $post['nickname'] = str_repeat('あ', 50);
        self::assertSame(303, $this->controller->join($this->request($token, $post))->status());
        self::assertSame($post['nickname'], $this->participants->findByToken($token)['nickname']);
        $post['nickname'] = '<script>alert(1)</script>';
        self::assertSame(303, $this->controller->join($this->request($token, $post))->status());
        $body = $this->controller->home($this->request($token))->body();
        self::assertStringNotContainsString('<script>', $body);
        self::assertStringNotContainsString('name="nickname"', $body);
        self::assertSame(str_repeat('あ', 50), $this->participants->findByToken($token)['nickname']);
        $post['nickname'] = '';
        self::assertSame(303, $this->controller->join($this->request($token, $post))->status());
        self::assertSame($id, (int) $this->participants->findByToken($token)['id']);
        self::assertSame(1, $this->participants->acquisitionCount($id));
        self::assertSame(str_repeat('あ', 50), $this->participants->findByToken($token)['nickname']);
        $this->participants->registerNickname($id, '直接の上書き');
        self::assertSame(str_repeat('あ', 50), $this->participants->findByToken($token)['nickname']);
    }

    public function testInvalidOrUnavailableSpotNeverAwardsStamp(): void
    {
        self::assertSame(404, $this->controller->spot(new Request([]), 'invalid')->status());
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM participants')->fetchColumn());
        foreach (["is_paused = 1", "is_paused = 0, starts_at = '2100-01-01T00:00:00Z', ends_at = '2101-01-01T00:00:00Z'", "starts_at = '2000-01-01T00:00:00Z', ends_at = '2001-01-01T00:00:00Z'"] as $update) {
            $this->database->exec('UPDATE events SET ' . $update);
            $response = $this->controller->spot(new Request([]), $this->spotToken);
            self::assertStringNotContainsString('スタンプを取得しました', $response->body());
        }
        $this->database->exec("UPDATE events SET ends_at = '2100-01-01T00:00:00Z'");
        $this->spots->setActive(1, false);
        self::assertStringContainsString('現在停止中', $this->controller->spot(new Request([]), $this->spotToken)->body());
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM stamp_acquisitions')->fetchColumn());
    }

    public function testExistingParticipantKeepsIdentityAndNewRequestsCannotEditThem(): void
    {
        $token = (new ParticipantToken())->generate();
        $id = $this->participants->create($token, '既存の参加者');
        $response = $this->controller->home($this->request($token));
        self::assertStringContainsString('既存の参加者', $response->body());
        $headers = (new \ReflectionProperty(Response::class, 'headers'))->getValue($response);
        self::assertArrayNotHasKey('Set-Cookie', $headers);
        $response = $this->controller->join(new Request([], [], ['_csrf' => $this->csrf->get(), 'nickname' => '他人', 'participant_id' => (string) $id]));
        self::assertSame(303, $response->status());
        self::assertSame('既存の参加者', $this->participants->findByToken($token)['nickname']);
        $response = $this->controller->spot($this->request($token), $this->spotToken);
        self::assertSame(1, $this->participants->acquisitionCount($id));
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM participants')->fetchColumn());
    }

    public function testEventNotConfiguredDoesNotRegisterParticipant(): void
    {
        $this->database->exec('DELETE FROM events');
        self::assertStringContainsString('準備中', $this->controller->home(new Request([]))->body());
        $this->controller->spot(new Request([]), $this->spotToken);
        self::assertSame(0, (int) $this->database->query('SELECT COUNT(*) FROM participants')->fetchColumn());
    }
}
