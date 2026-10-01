<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use QrRally\Tools\Release\ReleaseBuilder;
use QrRally\Tools\Release\ReleaseBuildException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

require_once dirname(__DIR__, 2) . '/tools/lib/ReleaseBuildException.php';
require_once dirname(__DIR__, 2) . '/tools/lib/ReleaseBuilder.php';

final class ReleaseBuilderTest extends TestCase
{
    private string $temporaryRoot;
    private string $project;
    private string $output;

    /** @var list<list<string>> */
    private array $commands = [];

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/release-builder-test-' . bin2hex(random_bytes(8));
        $this->project = $this->temporaryRoot . '/project';
        $this->output = $this->temporaryRoot . '/dist';
        $this->createFixture();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testBuildsAllowlistedZipAndChecksum(): void
    {
        $result = $this->builder()->build();

        self::assertSame('0.1.0', $result['version']);
        self::assertFileExists($result['zip']);
        self::assertFileExists($result['checksum']);
        self::assertSame(hash_file('sha256', $result['zip']), $result['sha256']);
        self::assertSame(
            $result['sha256'] . "  qr-stamp-rally-0.1.0.zip\n",
            file_get_contents($result['checksum']),
        );

        $zip = new ZipArchive();
        self::assertTrue($zip->open($result['zip']) === true);
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[] = $zip->getNameIndex($index);
        }
        $zip->close();

        self::assertContains('qr-stamp-rally-0.1.0/README.md', $entries);
        self::assertContains('qr-stamp-rally-0.1.0/vendor/autoload.php', $entries);
        self::assertContains('qr-stamp-rally-0.1.0/config/.htaccess', $entries);
        self::assertNotContains('qr-stamp-rally-0.1.0/config/app.php', $entries);
        self::assertNotContains('qr-stamp-rally-0.1.0/tests/example.php', $entries);
        self::assertFalse($this->containsPath($entries, '/tools/'));
        self::assertFalse($this->containsPath($entries, '/storage/'));
        self::assertFalse($this->containsPath($entries, '/ops/'));

        self::assertTrue($this->commandWasRunWith('install', '--no-dev'));
        self::assertTrue($this->commandWasRunWith('phpunit', '--colors=never'));
    }

    public function testRejectsDirtyGitTreeBeforeCreatingOutput(): void
    {
        $builder = $this->builder(gitStatus: ' M README.md');

        $this->expectException(ReleaseBuildException::class);
        $this->expectExceptionMessage('未コミットの変更');
        try {
            $builder->build();
        } finally {
            self::assertDirectoryDoesNotExist($this->output);
        }
    }

    public function testRejectsMissingRequiredDocument(): void
    {
        unlink($this->project . '/INSTALL.md');

        $this->expectException(ReleaseBuildException::class);
        $this->expectExceptionMessage('必須ファイル');
        $this->builder()->build();
    }

    public function testDoesNotOverwriteExistingArtifact(): void
    {
        mkdir($this->output, 0700, true);
        file_put_contents($this->output . '/qr-stamp-rally-0.1.0.zip', 'existing');

        $this->expectException(ReleaseBuildException::class);
        $this->expectExceptionMessage('自動上書きは行いません');
        $this->builder()->build();
    }

    public function testRejectsInvalidVersion(): void
    {
        file_put_contents($this->project . '/VERSION', "version-one\n");

        $this->expectException(ReleaseBuildException::class);
        $this->expectExceptionMessage('Semantic Versioning');
        $this->builder()->build();
    }

    public function testRejectsSymlinkAsOutputDirectory(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('symlink is not available.');
        }
        $outside = $this->temporaryRoot . '/outside';
        mkdir($outside, 0700, true);
        self::assertTrue(symlink($outside, $this->output));

        $this->expectException(ReleaseBuildException::class);
        $this->expectExceptionMessage('distの生成先にシンボリックリンク');
        $this->builder()->build();
    }

    public function testRejectsDevelopmentDependencyInVendor(): void
    {
        $this->expectException(ReleaseBuildException::class);
        $this->expectExceptionMessage('本番依存と一致しません');
        $this->builder(includeDevelopmentPackage: true)->build();
    }

    public function testRejectsSymlinkInAllowlistedSource(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('symlink is not available.');
        }
        self::assertTrue(symlink($this->project . '/README.md', $this->project . '/public/readme-link'));

        $this->expectException(ReleaseBuildException::class);
        $this->expectExceptionMessage('シンボリックリンク');
        $this->builder()->build();
    }

    private function builder(string $gitStatus = '', bool $includeDevelopmentPackage = false): ReleaseBuilder
    {
        $productionPackages = $this->productionPackages();
        $runner = function (array $command, string $workingDirectory) use (
            $gitStatus,
            $includeDevelopmentPackage,
            $productionPackages,
        ): array {
            $this->commands[] = $command;
            if ($command[0] === 'git') {
                return ['exit_code' => 0, 'stdout' => $gitStatus, 'stderr' => ''];
            }
            if (in_array('install', $command, true)) {
                $this->createFakeVendor($workingDirectory, $productionPackages, $includeDevelopmentPackage);
            }
            return ['exit_code' => 0, 'stdout' => 'OK', 'stderr' => ''];
        };
        return new ReleaseBuilder($this->project, $this->output, $runner, '/fake/composer');
    }

    private function createFixture(): void
    {
        $directories = [
            'public/assets/fonts', 'src', 'templates', 'database/migrations', 'config', 'bin', 'docs',
            'tests', 'storage', 'vendor/bin', 'ops/demo-vps',
        ];
        foreach ($directories as $directory) {
            mkdir($this->project . '/' . $directory, 0700, true);
        }

        $files = [
            'public/index.php' => '<?php echo "public";',
            'public/assets/fonts/NotoSansJP-LICENSE.txt' => 'OFL',
            'src/App.php' => '<?php namespace Fixture; final class App {}',
            'templates/page.php' => '<?php echo "template";',
            'database/migrations/001.sql' => 'CREATE TABLE example (id INTEGER);',
            'bootstrap.php' => '<?php return [];',
            '.htaccess' => 'Options -Indexes',
            'config/.htaccess' => 'Require all denied',
            'config/app.php' => '<?php return ["secret" => true];',
            'bin/migrate.php' => '<?php echo "migrate";',
            'bin/reset-admin-credentials.php' => '<?php echo "reset";',
            'VERSION' => "0.1.0\n",
            'README.md' => '# Readme',
            'INSTALL.md' => '# Install',
            'RELEASE_NOTES.md' => '# Releases',
            'LICENSE.txt' => 'License',
            'THIRD_PARTY_NOTICES.md' => '# Notices',
            'docs/ADMIN_GUIDE.md' => '# Admin',
            'docs/PARTICIPANT_GUIDE.md' => '# Participant',
            'docs/TROUBLESHOOTING.md' => '# Troubleshooting',
            'docs/UNINSTALL.md' => '# Uninstall',
            'tests/example.php' => '<?php echo "test";',
            'storage/app.sqlite' => 'database',
            'ops/demo-vps/reset.php' => '<?php echo "demo only";',
            'vendor/bin/phpunit' => '#!/usr/bin/env php',
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($this->project . '/' . $relative, $contents);
        }

        $packages = array_map(
            static fn (string $name): array => ['name' => $name, 'version' => '1.0.0'],
            $this->productionPackages(),
        );
        file_put_contents($this->project . '/composer.json', json_encode([
            'require' => ['php' => '^8.3'],
            'require-dev' => ['phpunit/phpunit' => '^12.0'],
        ], JSON_PRETTY_PRINT));
        file_put_contents($this->project . '/composer.lock', json_encode([
            'packages' => $packages,
            'packages-dev' => [['name' => 'phpunit/phpunit', 'version' => '12.0.0']],
        ], JSON_PRETTY_PRINT));
    }

    /** @param list<string> $productionPackages */
    private function createFakeVendor(string $stage, array $productionPackages, bool $includeDevelopmentPackage): void
    {
        $licensePaths = [
            'bacon/bacon-qr-code/LICENSE',
            'composer/LICENSE',
            'dasprid/enum/LICENSE',
            'dompdf/dompdf/LICENSE.LGPL',
            'dompdf/php-font-lib/LICENSE',
            'dompdf/php-svg-lib/LICENSE',
            'endroid/qr-code/LICENSE',
            'masterminds/html5/LICENSE.txt',
            'sabberworm/php-css-parser/LICENSE',
            'thecodingmachine/safe/LICENSE',
        ];
        foreach ($licensePaths as $relative) {
            $path = $stage . '/vendor/' . $relative;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            file_put_contents($path, 'license');
        }
        if (!is_dir($stage . '/vendor/composer')) {
            mkdir($stage . '/vendor/composer', 0700, true);
        }
        file_put_contents($stage . '/vendor/autoload.php', '<?php return true;');
        $names = $productionPackages;
        if ($includeDevelopmentPackage) {
            $names[] = 'phpunit/phpunit';
        }
        file_put_contents($stage . '/vendor/composer/installed.json', json_encode([
            'packages' => array_map(
                static fn (string $name): array => ['name' => $name, 'version' => '1.0.0'],
                $names,
            ),
        ], JSON_PRETTY_PRINT));
    }

    /** @return list<string> */
    private function productionPackages(): array
    {
        return [
            'bacon/bacon-qr-code',
            'dasprid/enum',
            'dompdf/dompdf',
            'dompdf/php-font-lib',
            'dompdf/php-svg-lib',
            'endroid/qr-code',
            'masterminds/html5',
            'sabberworm/php-css-parser',
            'thecodingmachine/safe',
        ];
    }

    /** @param list<string> $entries */
    private function containsPath(array $entries, string $fragment): bool
    {
        foreach ($entries as $entry) {
            if (is_string($entry) && str_contains($entry, $fragment)) {
                return true;
            }
        }
        return false;
    }

    private function commandWasRunWith(string ...$arguments): bool
    {
        foreach ($this->commands as $command) {
            foreach ($arguments as $argument) {
                $found = false;
                foreach ($command as $part) {
                    if ($part === $argument || str_contains($part, $argument)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    continue 2;
                }
            }
            return true;
        }
        return false;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
