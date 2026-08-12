<?php

declare(strict_types=1);

namespace QrRally\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;

final class DatabaseTest extends TestCase
{
    private string $directory;
    private string $databasePath;
    private PDO $database;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qr-rally-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->databasePath = $this->directory . '/test.sqlite';
        $this->database = (new ConnectionFactory())->connect($this->databasePath, 2500);
    }

    protected function tearDown(): void
    {
        unset($this->database);
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testConnectionEnablesRequiredPragmas(): void
    {
        self::assertSame(1, (int) $this->database->query('PRAGMA foreign_keys')->fetchColumn());
        self::assertSame('wal', strtolower((string) $this->database->query('PRAGMA journal_mode')->fetchColumn()));
        self::assertSame(2500, (int) $this->database->query('PRAGMA busy_timeout')->fetchColumn());
    }

    public function testMigrationsAreIdempotentAndSchemaIsValid(): void
    {
        $migrator = new Migrator($this->database, dirname(__DIR__, 2) . '/database/migrations');

        self::assertSame([
            '001_create_initial_schema.sql',
            '002_add_admin_login_attempts.sql',
            '003_add_admin_auth_version.sql',
            '004_add_spot_token_version.sql',
        ], $migrator->migrate());
        self::assertSame([], $migrator->migrate());
        self::assertSame('ok', $this->database->query('PRAGMA integrity_check')->fetchColumn());
        self::assertSame(4, (int) $this->database->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        self::assertSame(4, (int) $this->database->query('SELECT COUNT(*) FROM application_fields')->fetchColumn());
    }
}
