<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QrRally\Support\ProductVersion;
use RuntimeException;

final class ProductVersionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qr-product-version-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testReadsSemanticVersionFromFile(): void
    {
        $path = $this->writeVersion("0.1.0-rc.1+build.2\n");

        self::assertSame('0.1.0-rc.1+build.2', (new ProductVersion($path))->value());
    }

    #[DataProvider('invalidVersionProvider')]
    public function testRejectsInvalidVersion(string $version): void
    {
        $path = $this->writeVersion($version);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('VERSIONファイル');
        (new ProductVersion($path))->value();
    }

    /** @return iterable<string, array{string}> */
    public static function invalidVersionProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'missing patch' => ['0.1'];
        yield 'leading zero' => ['01.0.0'];
        yield 'space' => ['0.1.0 rc.1'];
    }

    public function testSynchronizesDifferentDatabaseVersionOnlyOnce(): void
    {
        $path = $this->writeVersion('0.1.0');
        $database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->exec('CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $database->exec("INSERT INTO app_settings VALUES ('product_version', '0.1.0-dev', 'before')");
        $versions = new ProductVersion($path);

        $versions->synchronize($database);
        $first = $database->query("SELECT setting_value, updated_at FROM app_settings WHERE setting_key = 'product_version'")->fetch(PDO::FETCH_ASSOC);
        $versions->synchronize($database);
        $second = $database->query("SELECT setting_value, updated_at FROM app_settings WHERE setting_key = 'product_version'")->fetch(PDO::FETCH_ASSOC);

        self::assertSame('0.1.0', $first['setting_value']);
        self::assertSame($first, $second);
    }

    public function testRejectsDatabaseWithoutProductVersion(): void
    {
        $path = $this->writeVersion('0.1.0');
        $database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->exec('CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, updated_at TEXT NOT NULL)');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DBに製品バージョン情報');
        (new ProductVersion($path))->synchronize($database);
    }

    private function writeVersion(string $version): string
    {
        $path = $this->directory . '/VERSION';
        file_put_contents($path, $version);
        return $path;
    }
}
