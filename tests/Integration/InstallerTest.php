<?php

declare(strict_types=1);

namespace QrRally\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use QrRally\Backup\BackupManager;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;
use QrRally\Domain\SpotInput;
use QrRally\Install\EnvironmentInspector;
use QrRally\Install\InstallException;
use QrRally\Install\InstallInput;
use QrRally\Install\Installer;
use QrRally\Repository\SpotRepository;
use QrRally\Security\SpotToken;

final class InstallerTest extends TestCase
{
    private string $directory;
    private string $applicationRoot;
    private string $publicConfig;
    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qr-installer-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/public-app/config', 0700, true);
        $this->applicationRoot = dirname(__DIR__, 2);
        $this->publicConfig = $this->directory . '/public-app/config/app.php';
        $this->server = [
            'HTTP_HOST' => 'localhost',
            'SCRIPT_NAME' => '/qr-sr/public/install.php',
            'DOCUMENT_ROOT' => $this->directory . '/public-app',
            'SERVER_PORT' => '80',
        ];
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testInstallsAtomicallyAndDoesNotPersistPlainRecoveryKey(): void
    {
        $private = $this->directory . '/private-data';
        $installer = $this->installer();
        $input = $this->input($private);
        $result = $installer->install($input, $this->server);

        self::assertFileExists($this->publicConfig);
        self::assertFileExists($private . '/config/app.php');
        self::assertFileExists($private . '/storage/app.sqlite');
        $config = require $this->publicConfig;
        self::assertSame($private . '/storage/app.sqlite', $config['database_path']);
        self::assertFalse($config['demo_mode']);
        self::assertSame('http://localhost/qr-sr/admin/login', $result['login_url']);
        self::assertNotContains(false, $result['path_diagnostics']);
        self::assertStringNotContainsString($result['recovery_key'], (string) file_get_contents($this->publicConfig));
        self::assertStringNotContainsString($result['recovery_key'], (string) file_get_contents($private . '/config/app.php'));
        self::assertStringNotContainsString($result['recovery_key'], (string) file_get_contents($private . '/storage/app.sqlite'));

        $database = (new ConnectionFactory())->connect($config['database_path']);
        $admin = $database->query('SELECT email, password_hash, recovery_key_hash FROM admins')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('admin@example.test', $admin['email']);
        self::assertTrue(password_verify('strong-password-123', $admin['password_hash']));
        self::assertTrue(password_verify($result['recovery_key'], $admin['recovery_key_hash']));
        self::assertSame(1, (int) $database->query('SELECT COUNT(*) FROM admins')->fetchColumn());
        self::assertSame('0.1.1', $database->query(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'product_version'",
        )->fetchColumn());
        self::assertSame('1', $database->query(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'schema_version'",
        )->fetchColumn());

        $this->expectException(InstallException::class);
        $this->expectExceptionMessage('すでにインストール');
        $installer->install($input, $this->server);
    }

    public function testRestoresBackupIntoUninstalledEnvironment(): void
    {
        $sourceDirectory = $this->directory . '/source';
        mkdir($sourceDirectory, 0700, true);
        $sourceDatabase = $sourceDirectory . '/app.sqlite';
        $source = (new ConnectionFactory())->connect($sourceDatabase);
        (new Migrator($source, $this->applicationRoot . '/database/migrations'))->migrate();
        $source->exec(
            "UPDATE app_settings SET setting_value = '0.1.0-dev' WHERE setting_key = 'product_version'",
        );
        $source->exec(
            "INSERT INTO admins (email, password_hash, recovery_key_hash, created_at, updated_at) "
            . "VALUES ('restored@example.test', 'password-hash', 'recovery-hash', strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))",
        );
        $sourceSpots = new SpotRepository($source, new SpotToken(str_repeat('a', 64)));
        $spotId = $sourceSpots->create(new SpotInput('復元スポット', '移転後にQRを更新'));
        $oldToken = $sourceSpots->publicToken($sourceSpots->find($spotId));
        $sourceQrKey = str_repeat('a', 64);
        $backup = (new BackupManager(
            $sourceDatabase,
            $sourceDirectory . '/backups',
            'https://old.example/',
            5000,
            null,
            $sourceQrKey,
        ))->create();
        unset($source);

        $private = $this->directory . '/restored-private';
        $input = $this->input($private);
        $installer = $this->installer();
        $validated = $installer->validateRestore($input, $this->server, $backup['path'], (int) filesize($backup['path']));
        $result = $installer->restore($input, $this->server, $validated['database_path'], $validated['manifest']);

        self::assertSame('https://old.example/', $result['source_base_url']);
        $database = (new ConnectionFactory())->connect($private . '/storage/app.sqlite');
        self::assertSame('restored@example.test', $database->query('SELECT email FROM admins')->fetchColumn());
        self::assertSame('0.1.1', $database->query(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'product_version'",
        )->fetchColumn());
        self::assertSame('1', $database->query(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'schema_version'",
        )->fetchColumn());
        self::assertSame(1, (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE event_type = 'backup.restore.new_environment'")->fetchColumn());
        $config = require $this->publicConfig;
        self::assertSame($sourceQrKey, $config['spot_token_key']);
        self::assertNotSame($sourceQrKey, $config['app_key']);
        $restoredSpots = new SpotRepository($database, new SpotToken($config['spot_token_key']));
        self::assertSame($oldToken, $restoredSpots->publicToken($restoredSpots->find($spotId)));
        self::assertSame($spotId, $restoredSpots->findByPublicToken($oldToken)['id']);
        self::assertSame(0, (int) $database->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'backup_secrets'")->fetchColumn());
        self::assertFileExists($this->publicConfig);
    }

    public function testRejectsPrivatePathInsideDocumentRoot(): void
    {
        $input = $this->input($this->directory . '/public-app/private');
        $this->expectException(InstallException::class);
        $this->expectExceptionMessage('入力内容にエラー');
        $this->installer()->install($input, $this->server);
    }

    private function installer(): Installer
    {
        $inspector = new EnvironmentInspector($this->applicationRoot);
        return new Installer($this->applicationRoot, $this->publicConfig, $inspector);
    }

    private function input(string $private): InstallInput
    {
        return new InstallInput(
            'development',
            'http://localhost/qr-sr/',
            $private,
            'Asia/Tokyo',
            'admin@example.test',
            'strong-password-123',
            'strong-password-123',
        );
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
