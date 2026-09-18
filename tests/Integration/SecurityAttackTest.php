<?php

declare(strict_types=1);

namespace QrRally\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;
use QrRally\Domain\ApplicationInput;
use QrRally\Domain\EventInput;
use QrRally\Domain\SpotInput;
use QrRally\Repository\AdminRepository;
use QrRally\Repository\ApplicationRepository;
use QrRally\Repository\EventRepository;
use QrRally\Repository\ParticipantRepository;
use QrRally\Repository\SpotRepository;
use QrRally\Security\ParticipantToken;
use QrRally\Security\SpotToken;

final class SecurityAttackTest extends TestCase
{
    private string $directory;
    private PDO $database;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qr-security-attack-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->database = (new ConnectionFactory())->connect($this->directory . '/test.sqlite');
        (new Migrator($this->database, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
    }

    protected function tearDown(): void
    {
        unset($this->database);
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testUserControlledValuesCannotChangeSqlStructure(): void
    {
        $attack = "x'); DROP TABLE events; DROP TABLE spots; DROP TABLE participants; DROP TABLE admins; --";

        $admins = new AdminRepository($this->database);
        $adminId = $admins->create($attack, 'password-hash', 'recovery-hash');

        $events = new EventRepository($this->database);
        $events->save(
            new EventInput($attack, $attack, $attack, '', '', false, $attack, 1, $attack),
            '2026-09-18T00:00:00Z',
            '2026-09-19T00:00:00Z',
        );

        $spots = new SpotRepository($this->database, new SpotToken(str_repeat('a', 64)));
        $spotId = $spots->create(new SpotInput($attack, $attack));

        $participantToken = (new ParticipantToken())->generate();
        $participants = new ParticipantRepository(
            $this->database,
            new ParticipantToken(),
        );
        $participantId = $participants->create($participantToken, $attack);

        $applications = new ApplicationRepository($this->database);
        $application = $applications->save(
            $participantId,
            new ApplicationInput([
                'name' => $attack,
                'email' => $attack,
                'address' => $attack,
                'phone' => $attack,
            ], true),
            array_map(
                static fn (array $field): array => array_merge($field, ['is_enabled' => 1]),
                $applications->fields(),
            ),
        );

        self::assertSame(mb_strtolower($attack), $admins->findById($adminId)['email']);
        self::assertSame($attack, $events->find()['name']);
        self::assertSame($attack, $spots->find($spotId)['name']);
        self::assertSame($attack, $participants->findByToken($participantToken)['nickname']);
        self::assertSame($attack, $application['address']);
        self::assertGreaterThan(0, $participantId);

        foreach (['admins', 'events', 'spots', 'participants', 'applications'] as $table) {
            $statement = $this->database->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name",
            );
            $statement->execute(['name' => $table]);
            self::assertSame(1, (int) $statement->fetchColumn(), "{$table} table must remain intact");
        }
    }
}
