<?php

declare(strict_types=1);

namespace QrRally\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QrRally\Backup\BackupException;
use QrRally\Backup\BackupManager;
use QrRally\Backup\RestoreStateStore;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;
use ZipArchive;

final class BackupManagerTest extends TestCase
{
    private string $directory;
    private string $databasePath;
    private string $workRoot;
    private PDO $database;
    private BackupManager $manager;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qr-backup-test-' . bin2hex(random_bytes(6));
        $this->workRoot = $this->directory . '/backups';
        mkdir($this->directory, 0700, true);
        $this->databasePath = $this->directory . '/app.sqlite';
        $this->database = (new ConnectionFactory())->connect($this->databasePath, 1000);
        (new Migrator($this->database, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->database->exec(
            "INSERT INTO admins (email, password_hash, recovery_key_hash, created_at, updated_at) "
            . "VALUES ('admin@example.test', 'hash', 'recovery', strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))",
        );
        $this->manager = new BackupManager(
            $this->databasePath,
            $this->workRoot,
            'http://localhost/qr-sr/',
            1000,
            null,
            str_repeat('a', 64),
        );
    }

    protected function tearDown(): void
    {
        unset($this->database, $this->manager);
        $this->remove($this->directory);
    }

    public function testCreatesAndValidatesDedicatedBackup(): void
    {
        $backup = $this->manager->create();
        self::assertSame('qr-stamp-rally', $backup['manifest']['product']);
        self::assertSame(1, $backup['manifest']['backup_version']);
        self::assertSame(1, $backup['manifest']['table_counts']['admins']);
        self::assertSame(1, $backup['manifest']['table_counts']['backup_secrets']);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($backup['path']) === true);
        $names = [$zip->getNameIndex(0), $zip->getNameIndex(1)];
        sort($names);
        self::assertSame(['database.sqlite', 'manifest.json'], $names);
        $zip->close();

        $validated = $this->manager->validateUploaded($backup['path'], (int) filesize($backup['path']));
        self::assertSame($backup['manifest']['database_sha256'], $validated['manifest']['database_sha256']);
        self::assertFileExists($validated['database_path']);
    }

    public function testRejectsUnexpectedArchiveEntryWithoutChangingCurrentDatabase(): void
    {
        $before = hash_file('sha256', $this->databasePath);
        $path = $this->directory . '/invalid.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        $zip->addFromString('manifest.json', '{}');
        $zip->addFromString('database.sqlite', 'invalid');
        $zip->addFromString('../outside.txt', 'bad');
        $zip->close();

        try {
            $this->manager->validateUploaded($path, (int) filesize($path));
            self::fail('BackupException was not thrown.');
        } catch (BackupException $error) {
            self::assertStringContainsString('2ファイル', $error->getMessage());
        }
        self::assertSame($before, hash_file('sha256', $this->databasePath));
        self::assertFileDoesNotExist($this->directory . '/outside.txt');
    }

    #[DataProvider('invalidArchivePathProvider')]
    public function testRejectsArchivePathTraversalAndAbsolutePaths(string $invalidName): void
    {
        $path = $this->directory . '/invalid-path-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        $zip->addFromString($invalidName, '{}');
        $zip->addFromString('database.sqlite', 'invalid');
        $zip->close();

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('不正なパス');
        $this->manager->validateUploaded($path, (int) filesize($path));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidArchivePathProvider(): iterable
    {
        yield 'unix traversal' => ['../manifest.json'];
        yield 'unix absolute path' => ['/manifest.json'];
        yield 'windows traversal' => ['..\\manifest.json'];
        yield 'nested path' => ['nested/manifest.json'];
    }

    public function testRejectsSymbolicLinkArchiveEntry(): void
    {
        $path = $this->directory . '/symbolic-link.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        $zip->addFromString('manifest.json', 'database.sqlite');
        $zip->setExternalAttributesName('manifest.json', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->addFromString('database.sqlite', 'invalid');
        $zip->close();

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('シンボリックリンク');
        $this->manager->validateUploaded($path, (int) filesize($path));
    }

    public function testRestoresValidatedDatabaseAndRecordsCompletion(): void
    {
        $backup = $this->manager->create();
        $validated = $this->manager->validateUploaded($backup['path'], (int) filesize($backup['path']));
        $this->database->exec("UPDATE admins SET email = 'changed@example.test'");
        $this->database->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($this->database);

        $states = new RestoreStateStore($this->workRoot . '/restore-state.json');
        $this->manager->restore($validated['database_path'], $validated['manifest'], $states, 1);

        $restored = (new ConnectionFactory())->connect($this->databasePath, 1000);
        self::assertSame('admin@example.test', $restored->query('SELECT email FROM admins WHERE id = 1')->fetchColumn());
        self::assertSame(1, (int) $restored->query("SELECT COUNT(*) FROM backup_operations WHERE operation_type = 'restore' AND status = 'completed'")->fetchColumn());
        self::assertSame(1, (int) $restored->query("SELECT COUNT(*) FROM audit_logs WHERE event_type = 'backup.restore'")->fetchColumn());
        self::assertSame(0, (int) $restored->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'backup_secrets'")->fetchColumn());
        self::assertSame('completed', $states->read()['status'] ?? null);
        $this->database = $restored;
    }

    public function testRejectsHashMismatchWithoutChangingCurrentDatabase(): void
    {
        $backup = $this->manager->create();
        $before = hash_file('sha256', $this->databasePath);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($backup['path']) === true);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        $database = (string) $zip->getFromName('database.sqlite');
        $zip->close();
        $manifest['database_sha256'] = str_repeat('0', 64);
        $invalid = $this->directory . '/hash-mismatch.zip';
        $this->writeZip($invalid, json_encode($manifest, JSON_THROW_ON_ERROR), $database);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('ハッシュが一致しません');
        try {
            $this->manager->validateUploaded($invalid, (int) filesize($invalid));
        } finally {
            self::assertSame($before, hash_file('sha256', $this->databasePath));
        }
    }

    public function testRejectsDuplicateManifestKey(): void
    {
        $backup = $this->manager->create();
        $zip = new ZipArchive();
        self::assertTrue($zip->open($backup['path']) === true);
        $database = (string) $zip->getFromName('database.sqlite');
        $zip->close();
        $invalid = $this->directory . '/duplicate-key.zip';
        $this->writeZip($invalid, '{"product":"qr-stamp-rally","product":"other"}', $database);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('重複したキー');
        $this->manager->validateUploaded($invalid, (int) filesize($invalid));
    }

    public function testIntentionalSwitchFailurePreservesCurrentDatabase(): void
    {
        $backup = $this->manager->create();
        $validated = $this->manager->validateUploaded($backup['path'], (int) filesize($backup['path']));
        $this->database->exec("UPDATE admins SET email = 'current@example.test'");
        $this->database->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($this->database);
        $before = hash_file('sha256', $this->databasePath);
        $manager = new BackupManager(
            $this->databasePath,
            $this->workRoot,
            'http://localhost/qr-sr/',
            1000,
            static fn () => throw new \RuntimeException('intentional failure'),
        );
        $states = new RestoreStateStore($this->workRoot . '/restore-state.json');

        try {
            $manager->restore($validated['database_path'], $validated['manifest'], $states, 1);
            self::fail('BackupException was not thrown.');
        } catch (BackupException) {
            self::assertSame($before, hash_file('sha256', $this->databasePath));
            self::assertSame('failed', $states->read()['status'] ?? null);
        }
        $this->database = (new ConnectionFactory())->connect($this->databasePath, 1000);
        self::assertSame('current@example.test', $this->database->query('SELECT email FROM admins')->fetchColumn());
    }

    private function writeZip(string $path, string $manifest, string $database): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        $zip->addFromString('manifest.json', $manifest);
        $zip->addFromString('database.sqlite', $database);
        $zip->close();
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
