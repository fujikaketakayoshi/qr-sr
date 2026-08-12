<?php

declare(strict_types=1);

namespace QrRally\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;
use QrRally\Domain\SpotInput;
use QrRally\Repository\ParticipantRepository;
use QrRally\Repository\SpotRepository;
use QrRally\Security\ParticipantToken;
use QrRally\Security\SpotToken;

final class ParticipantRepositoryTest extends TestCase
{
    private string $directory;
    private PDO $database;
    private ParticipantRepository $participants;
    private SpotRepository $spots;
    private ParticipantToken $tokens;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qr-participant-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->database = (new ConnectionFactory())->connect($this->directory . '/test.sqlite');
        (new Migrator($this->database, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->tokens = new ParticipantToken();
        $this->participants = new ParticipantRepository($this->database, $this->tokens);
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

    public function testStoresOnlyTokenHashAndFindsParticipant(): void
    {
        $token = $this->tokens->generate();
        $id = $this->participants->create($token, 'テスト参加者');

        self::assertSame($id, $this->participants->findByToken($token)['id']);
        self::assertSame(hash('sha256', $token), $this->database->query('SELECT token_hash FROM participants')->fetchColumn());
        self::assertStringNotContainsString($token, file_get_contents($this->directory . '/test.sqlite'));
    }

    public function testDuplicateAcquisitionDoesNotCreateAnotherRow(): void
    {
        $participantId = $this->participants->create($this->tokens->generate(), '参加者');
        $spotId = $this->spots->create(new SpotInput('図書館', ''));

        self::assertSame('acquired', $this->participants->acquire($participantId, $spotId, 'ip-hash'));
        self::assertSame('duplicate', $this->participants->acquire($participantId, $spotId, 'ip-hash'));
        self::assertSame(1, $this->participants->acquisitionCount($participantId));
    }

    public function testCompletionIsSetOnceAndStoppedAcquisitionRemainsOnBoard(): void
    {
        $participantId = $this->participants->create($this->tokens->generate(), '参加者');
        $spotId = $this->spots->create(new SpotInput('停止予定', ''));
        $this->participants->acquire($participantId, $spotId, null);
        $this->spots->setActive($spotId, false);

        self::assertTrue($this->participants->markCompletedIfEligible($participantId, 1));
        self::assertNotNull($this->participants->stampBoard($participantId)[0]['acquired_at']);
        self::assertSame(1, $this->participants->acquisitionCount($participantId));
        self::assertNotNull($this->database->query("SELECT completed_at FROM participants WHERE id = {$participantId}")->fetchColumn());
    }
}
