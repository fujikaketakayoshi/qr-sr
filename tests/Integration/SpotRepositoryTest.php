<?php

declare(strict_types=1);

namespace QrRally\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;
use QrRally\Domain\SpotInput;
use QrRally\Repository\SpotRepository;
use QrRally\Security\SpotToken;
use RuntimeException;

final class SpotRepositoryTest extends TestCase
{
    private string $directory;
    private PDO $database;
    private SpotRepository $spots;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qr-spot-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->database = (new ConnectionFactory())->connect($this->directory . '/test.sqlite');
        (new Migrator($this->database, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->spots = new SpotRepository($this->database, new SpotToken(str_repeat('a', 64)));
    }

    protected function tearDown(): void
    {
        unset($this->database);
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testCreatesWithHashOnlyAndResolvesCurrentToken(): void
    {
        $id = $this->spots->create(new SpotInput('図書館', '入口'));
        $spot = $this->spots->find($id);
        $token = $this->spots->publicToken($spot);

        self::assertSame($id, $this->spots->findByPublicToken($token)['id']);
        self::assertSame(hash('sha256', $token), $spot['public_token_hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $spot['management_token']);
        self::assertSame($id, $this->spots->findIdByManagementToken($spot['management_token']));
        self::assertStringNotContainsString($token, file_get_contents($this->directory . '/test.sqlite'));
    }

    public function testReissueInvalidatesOldTokenAndMoveChangesOrder(): void
    {
        $firstId = $this->spots->create(new SpotInput('1', ''));
        $secondId = $this->spots->create(new SpotInput('2', ''));
        $oldToken = $this->spots->publicToken($this->spots->find($firstId));

        $this->spots->reissueToken($firstId);
        $this->spots->move($secondId, 'up');

        self::assertNull($this->spots->findByPublicToken($oldToken));
        self::assertSame('2', $this->spots->all()[0]['name']);
    }

    public function testCannotDeleteSpotWithAcquisition(): void
    {
        $spotId = $this->spots->create(new SpotInput('取得済み', ''));
        $this->database->exec(
            "INSERT INTO participants (token_hash,nickname,first_seen_at,last_seen_at,created_at,updated_at) VALUES ('p','参加者','now','now','now','now')",
        );
        $this->database->exec(
            "INSERT INTO stamp_acquisitions (participant_id,spot_id,acquired_at) VALUES (1,{$spotId},'now')",
        );

        $this->expectException(RuntimeException::class);
        $this->spots->delete($spotId);
    }

    public function testSpotInputCannotInjectSql(): void
    {
        $attack = "'); DROP TABLE spots; --";
        $id = $this->spots->create(new SpotInput($attack, $attack));

        self::assertSame($attack, $this->spots->find($id)['name']);
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM spots')->fetchColumn());
    }
}
