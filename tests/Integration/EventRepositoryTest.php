<?php

declare(strict_types=1);

namespace QrRally\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;
use QrRally\Domain\ApplicationDefaults;
use QrRally\Domain\EventInput;
use QrRally\Repository\EventRepository;

final class EventRepositoryTest extends TestCase
{
    private string $directory;
    private PDO $database;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qr-event-test-' . bin2hex(random_bytes(6));
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

    public function testCreatesThenUpdatesTheSingleEvent(): void
    {
        $events = new EventRepository($this->database);
        $input = new EventInput('最初のイベント', '', '', '', '', false, '', 3, '');

        self::assertTrue($events->save($input, '2026-08-11T01:00:00Z', '2026-08-11T09:00:00Z'));
        self::assertSame(ApplicationDefaults::PRIVACY_PURPOSE, $events->find()['privacy_purpose_text']);
        self::assertFalse($events->save(
            new EventInput('更新イベント', '', '', '', '', true, '一時停止中', 5, ''),
            '2026-08-12T01:00:00Z',
            '2026-08-12T09:00:00Z',
        ));
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM events')->fetchColumn());
        self::assertSame('更新イベント', $events->find()['name']);
        self::assertSame(1, $events->find()['is_paused']);
    }
}
