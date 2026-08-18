<?php

declare(strict_types=1);

namespace QrRally\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use QrRally\Auth\PasswordPolicy;
use QrRally\Auth\CredentialUpdater;
use QrRally\Auth\PasswordResetter;
use QrRally\Auth\RecoveryKey;
use QrRally\Auth\AdminAuth;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;
use QrRally\Repository\AdminRepository;
use QrRally\Repository\AuditLogRepository;
use QrRally\Repository\LoginAttemptRepository;
use QrRally\Security\CsrfToken;
use QrRally\Session\SessionManager;

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
            new CredentialUpdater(
                $this->database,
                $admins,
                new AuditLogRepository($this->database),
                new RecoveryKey(),
            ),
        );
        $result = $resetter->reset('admin@example.test', $originalKey, 'another-secure-password');

        self::assertTrue($result['success']);
        self::assertArrayHasKey('recovery_key', $result);
        $updated = $admins->findById($id);
        self::assertTrue(password_verify('another-secure-password', $updated['password_hash']));
        self::assertFalse(password_verify($originalKey, $updated['recovery_key_hash']));
        self::assertTrue(password_verify($result['recovery_key'], $updated['recovery_key_hash']));
        self::assertSame(2, $updated['auth_version']);

        $databaseDump = file_get_contents($this->directory . '/test.sqlite');
        self::assertStringNotContainsString('very-secure-password', $databaseDump);
        self::assertStringNotContainsString($originalKey, $databaseDump);
    }

    public function testSixthFailedLoginWithinWindowIsRateLimitedWithoutStoringPlainIp(): void
    {
        $admins = new AdminRepository($this->database);
        $admins->create(
            'admin@example.test',
            password_hash('correct-secure-password', PASSWORD_DEFAULT),
            password_hash('recovery-key', PASSWORD_DEFAULT),
        );
        $auth = new AdminAuth(
            $admins,
            new LoginAttemptRepository($this->database),
            new AuditLogRepository($this->database),
            new SessionManager(),
            new CsrfToken(),
            str_repeat('a', 64),
            7200,
        );
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            self::assertFalse($auth->attempt('admin@example.test', 'wrong-password', '203.0.113.50')['success']);
        }
        $blocked = $auth->attempt('admin@example.test', 'wrong-password', '203.0.113.50');

        self::assertFalse($blocked['success']);
        self::assertStringContainsString('15分ほど待って', $blocked['message']);
        self::assertSame(5, (int) $this->database->query('SELECT COUNT(*) FROM admin_login_attempts')->fetchColumn());
        self::assertStringNotContainsString('203.0.113.50', (string) file_get_contents($this->directory . '/test.sqlite'));
    }
}
