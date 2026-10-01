<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use QrRally\Config\Config;

final class ConfigTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'qr-config-');
        putenv('APP_ENV');
        putenv('APP_BASE_URL');
        putenv('APP_DATABASE_PATH');
        putenv('SPOT_TOKEN_KEY');
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('APP_BASE_URL');
        putenv('APP_DATABASE_PATH');
        putenv('SPOT_TOKEN_KEY');
        @unlink($this->path);
    }

    public function testLoadsDevelopmentConfiguration(): void
    {
        $this->writeConfiguration();

        $config = Config::load($this->path);

        self::assertSame('development', $config->string('env'));
        self::assertFalse($config->bool('cookie_secure'));
        self::assertSame(str_repeat('a', 64), $config->string('spot_token_key'));
        self::assertFalse($config->bool('demo_mode'));
    }

    public function testEnvironmentVariablesOverrideLocationValues(): void
    {
        $this->writeConfiguration();
        putenv('APP_BASE_URL=https://example.test/qr/');

        self::assertSame('https://example.test/qr/', Config::load($this->path)->string('base_url'));
    }

    public function testRejectsUnsafeProductionConfiguration(): void
    {
        $this->writeConfiguration(['env' => 'production']);

        $this->expectException(InvalidArgumentException::class);
        Config::load($this->path);
    }

    public function testLoadsDemoModeInSafeProductionConfiguration(): void
    {
        $this->writeConfiguration([
            'env' => 'production',
            'base_url' => 'https://example.test/',
            'debug' => false,
            'cookie_secure' => true,
            'allow_development_tools' => false,
            'demo_mode' => true,
        ]);

        self::assertTrue(Config::load($this->path)->bool('demo_mode'));
    }

    public function testRejectsDemoModeInDevelopmentConfiguration(): void
    {
        $this->writeConfiguration(['demo_mode' => true]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Demo mode requires the production environment.');
        Config::load($this->path);
    }

    /** @param array<string, mixed> $overrides */
    private function writeConfiguration(array $overrides = []): void
    {
        $values = array_replace([
            'env' => 'development',
            'base_url' => 'http://127.0.0.1/',
            'app_key' => str_repeat('a', 64),
            'timezone' => 'Asia/Tokyo',
            'debug' => true,
            'log_level' => 'debug',
            'cookie_secure' => false,
            'allow_development_tools' => true,
            'database_path' => sys_get_temp_dir() . '/qr-test.sqlite',
            'database_busy_timeout_ms' => 5000,
            'session_name' => 'qr_rally_admin',
            'session_lifetime_seconds' => 7200,
        ], $overrides);

        file_put_contents($this->path, '<?php return ' . var_export($values, true) . ';');
    }
}
