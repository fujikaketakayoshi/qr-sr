<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QrRally\Install\EnvironmentInspector;
use QrRally\Install\InstallInput;

final class EnvironmentInspectorTest extends TestCase
{
    public function testRequiresSqlite326OrLater(): void
    {
        $inspector = new EnvironmentInspector('/srv/qr-sr');

        self::assertFalse($inspector->supportsSqliteVersion('3.25.3'));
        self::assertTrue($inspector->supportsSqliteVersion('3.26.0'));
        self::assertTrue($inspector->supportsSqliteVersion('3.51.0'));

        $diagnostics = $inspector->basic(['HTTPS' => 'on']);
        self::assertNotNull($diagnostics['pdo_sqlite_version']);
        self::assertNotNull($diagnostics['sqlite3_version']);
        self::assertSame('3.26.0', $diagnostics['minimum_sqlite_version']);
        self::assertTrue($diagnostics['sqlite_version_supported']);
    }

    public function testSuggestsSubdirectoryUrlAndPrivateSibling(): void
    {
        $inspector = new EnvironmentInspector('/srv/public_html/qr-sr');
        $server = [
            'HTTP_HOST' => 'example.test',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => '/qr-sr/public/install.php',
            'DOCUMENT_ROOT' => '/srv/public_html',
        ];
        self::assertSame('https://example.test/qr-sr/', $inspector->candidateBaseUrl($server));
        self::assertSame('/srv/qr-sr-private', $inspector->candidatePrivatePath($server));
    }

    public function testSuggestsPrivatePathOutsideApplicationWhenPublicDirectoryIsDocumentRoot(): void
    {
        $inspector = new EnvironmentInspector('/srv/qr-sr');
        $server = [
            'HTTP_HOST' => 'example.test',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => '/install.php',
            'DOCUMENT_ROOT' => '/srv/qr-sr/public',
        ];
        self::assertSame('https://example.test/', $inspector->candidateBaseUrl($server));
        self::assertSame('/srv/qr-sr-private', $inspector->candidatePrivatePath($server));
    }

    public function testRejectsProductionHttpAndMismatchedInstallPath(): void
    {
        $inspector = new EnvironmentInspector('/srv/public_html/qr-sr');
        $server = ['HTTP_HOST' => 'localhost', 'SCRIPT_NAME' => '/qr-sr/public/install.php', 'DOCUMENT_ROOT' => '/srv/public_html'];
        $production = new InstallInput('production', 'http://localhost/qr-sr/', '/srv/qr-sr-private', 'Asia/Tokyo');
        self::assertArrayHasKey('base_url', $inspector->validateInput($production, $server));
        $productionHttpsUrlOverHttp = new InstallInput('production', 'https://localhost/qr-sr/', '/srv/qr-sr-private', 'Asia/Tokyo');
        self::assertArrayHasKey('base_url', $inspector->validateInput($productionHttpsUrlOverHttp, $server));
        $wrongPath = new InstallInput('development', 'http://localhost/other/', '/srv/qr-sr-private', 'Asia/Tokyo');
        self::assertArrayHasKey('base_url', $inspector->validateInput($wrongPath, $server));
    }
}
