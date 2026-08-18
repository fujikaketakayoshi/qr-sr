<?php

declare(strict_types=1);

namespace QrRally\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;
use QrRally\Database\SqliteWriteRetrier;
use QrRally\Repository\ParticipantRepository;
use QrRally\Security\ParticipantToken;

final class SqliteLockRetryTest extends TestCase
{
    private string $directory;
    private string $path;
    private PDO $writer;
    private PDO $locker;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qr-lock-retry-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->path = $this->directory . '/test.sqlite';
        $this->writer = (new ConnectionFactory())->connect($this->path, 1);
        (new Migrator($this->writer, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->writer->exec("INSERT INTO spots(public_token_hash,name,display_order,created_at,updated_at) VALUES('s','場所',1,'now','now')");
        $this->writer->exec("INSERT INTO participants(token_hash,nickname,first_seen_at,last_seen_at,created_at,updated_at) VALUES('p','参加者','now','now','now','now')");
        $this->locker = (new ConnectionFactory())->connect($this->path, 1);
    }

    protected function tearDown(): void
    {
        unset($this->locker, $this->writer);
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testRealSqliteLockIsReleasedAndWriteRetriesOnce(): void
    {
        $this->locker->exec('BEGIN IMMEDIATE');
        $this->locker->exec("UPDATE participants SET nickname='ロック中' WHERE id=1");
        $delays = [];
        $retrier = new SqliteWriteRetrier([100, 250], function (int $milliseconds) use (&$delays): void {
            $delays[] = $milliseconds;
            $this->locker->exec('COMMIT');
        });
        $participants = new ParticipantRepository($this->writer, new ParticipantToken(), $retrier);

        self::assertSame('acquired', $participants->acquire(1, 1, null));
        self::assertSame([100], $delays);
        self::assertSame(1, (int) $this->writer->query('SELECT COUNT(*) FROM stamp_acquisitions')->fetchColumn());
    }
}
