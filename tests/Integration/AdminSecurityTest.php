<?php

declare(strict_types=1);

namespace QrRally\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use QrRally\Auth\PasswordPolicy;
use QrRally\Auth\PasswordResetter;
use QrRally\Auth\RecoveryKey;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;
use QrRally\Repository\AdminRepository;
use QrRally\Repository\AuditLogRepository;

final class AdminSecurityTest extends TestCase
{
    private string $directory;
    private PDO $database;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qr-admin-test-' . bin2hex(random_bytes(6));
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

    public function testPasswordsAndRecoveryKeysAreStoredOnlyAsHashesAndResetRotatesKey(): void
    {
        $admins = new AdminRepository($this->database);
        $originalKey = 'original-recovery-key-value';
        $id = $admins->create(
            'Admin@Example.test',
            password_hash('very-secure-password', PASSWORD_DEFAULT),
            password_hash($originalKey, PASSWORD_DEFAULT),
        );

        $stored = $admins->findById($id);
        self::assertNotSame('very-secure-password', $stored['password_hash']);
        self::assertNotSame($originalKey, $stored['recovery_key_hash']);

        $resetter = new PasswordResetter(
            $admins,
            new AuditLogRepository($this->database),
            new PasswordPolicy(),
            new RecoveryKey(),
        );
        $result = $resetter->reset('admin@example.test', $originalKey, 'another-secure-password');

        self::assertTrue($result['success']);
        self::assertArrayHasKey('recovery_key', $result);
        $updated = $admins->findById($id);
        self::assertTrue(password_verify('another-secure-password', $updated['password_hash']));
        self::assertFalse(password_verify($originalKey, $updated['recovery_key_hash']));
        self::assertTrue(password_verify($result['recovery_key'], $updated['recovery_key_hash']));

        $databaseDump = file_get_contents($this->directory . '/test.sqlite');
        self::assertStringNotContainsString('very-secure-password', $databaseDump);
        self::assertStringNotContainsString($originalKey, $databaseDump);
    }
}
