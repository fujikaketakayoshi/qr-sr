<?php

declare(strict_types=1);

namespace QrRally\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use QrRally\Auth\AdminAuth;
use QrRally\Auth\CredentialUpdater;
use QrRally\Auth\RecoveryKey;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;
use QrRally\Repository\AdminRepository;
use QrRally\Repository\AuditLogRepository;
use QrRally\Repository\LoginAttemptRepository;
use QrRally\Security\CsrfToken;
use QrRally\Session\SessionManager;

final class AuthVersionTest extends TestCase
{
    private string $directory;
    private PDO $database;
    private AdminRepository $admins;
    private AuditLogRepository $logs;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->directory = sys_get_temp_dir() . '/qr-auth-version-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->database = (new ConnectionFactory())->connect($this->directory . '/test.sqlite');
        (new Migrator($this->database, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->admins = new AdminRepository($this->database);
        $this->logs = new AuditLogRepository($this->database);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($this->database);
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testCredentialUpdateInvalidatesPreviouslyIssuedSessionWithoutDeletingEventData(): void
    {
        $adminId = $this->admins->create(
            'admin@example.test',
            password_hash('original-password', PASSWORD_DEFAULT),
            password_hash('original-recovery-key', PASSWORD_DEFAULT),
        );
        $this->database->exec(
            "INSERT INTO events (id, name, starts_at, ends_at, required_stamp_count, created_at, updated_at) "
            . "VALUES (1, '保持するイベント', '2026-08-11T00:00:00Z', '2026-08-12T00:00:00Z', 1, 'now', 'now')",
        );
        $_SESSION = [
            'admin_id' => $adminId,
            'last_activity_at' => time(),
            'auth_version' => 1,
        ];
        $auth = new AdminAuth(
            $this->admins,
            new LoginAttemptRepository($this->database),
            $this->logs,
            new SessionManager(),
            new CsrfToken(),
            str_repeat('a', 64),
            7200,
        );

        self::assertSame($adminId, $auth->id());
        (new CredentialUpdater($this->database, $this->admins, $this->logs, new RecoveryKey()))
            ->update(
                $adminId,
                'admin@example.test',
                'new-secure-password',
                'admin_credentials_reset_via_cli',
                'system',
            );

        self::assertNull($auth->id());
        self::assertSame('保持するイベント', $this->database->query('SELECT name FROM events')->fetchColumn());
        self::assertSame(1, (int) $this->database->query('SELECT COUNT(*) FROM audit_logs WHERE event_type = \'admin_credentials_reset_via_cli\'')->fetchColumn());
    }

    public function testSessionExpiresAfterConfiguredInactivity(): void
    {
        $adminId = $this->admins->create(
            'admin@example.test',
            password_hash('original-password', PASSWORD_DEFAULT),
            password_hash('original-recovery-key', PASSWORD_DEFAULT),
        );
        $_SESSION = ['admin_id' => $adminId, 'last_activity_at' => time() - 7201, 'auth_version' => 1];
        $auth = new AdminAuth(
            $this->admins,
            new LoginAttemptRepository($this->database),
            $this->logs,
            new SessionManager(),
            new CsrfToken(),
            str_repeat('a', 64),
            7200,
        );

        self::assertNull($auth->id());
    }
}
