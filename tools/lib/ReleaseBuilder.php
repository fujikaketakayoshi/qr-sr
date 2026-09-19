<?php

declare(strict_types=1);

namespace QrRally\Tools\Release;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

final class ReleaseBuilder
{
    /** @var list<string> */
    private const ALLOWED_DIRECTORIES = [
        'public',
        'src',
        'templates',
        'database/migrations',
    ];

    /** @var list<string> */
    private const ALLOWED_FILES = [
        'bootstrap.php',
        '.htaccess',
        'config/.htaccess',
        'bin/migrate.php',
        'bin/reset-admin-credentials.php',
        'composer.json',
        'composer.lock',
        'VERSION',
        'README.md',
        'INSTALL.md',
        'RELEASE_NOTES.md',
        'LICENSE.txt',
        'THIRD_PARTY_NOTICES.md',
        'docs/ADMIN_GUIDE.md',
        'docs/PARTICIPANT_GUIDE.md',
        'docs/TROUBLESHOOTING.md',
        'docs/UNINSTALL.md',
    ];

    /** @var list<string> */
    private const REQUIRED_VENDOR_LICENSES = [
        'vendor/bacon/bacon-qr-code/LICENSE',
        'vendor/composer/LICENSE',
        'vendor/dasprid/enum/LICENSE',
        'vendor/dompdf/dompdf/LICENSE.LGPL',
        'vendor/dompdf/php-font-lib/LICENSE',
        'vendor/dompdf/php-svg-lib/LICENSE',
        'vendor/endroid/qr-code/LICENSE',
        'vendor/masterminds/html5/LICENSE.txt',
        'vendor/sabberworm/php-css-parser/LICENSE',
        'vendor/thecodingmachine/safe/LICENSE',
        'public/assets/fonts/NotoSansJP-LICENSE.txt',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PATHS = [
        '.git',
        '.gitignore',
        '.phpunit.cache',
        'AGENTS.md',
        'config/app.php',
        'config/app.example.php',
        'output',
        'phpunit.xml.dist',
        'project-docs',
        'storage',
        'tests',
        'tools',
        'bin/create-admin.php',
        'bin/setup-local.php',
        'bin/reset-local.php',
        'bin/phase6-concurrent-worker.php',
        'bin/run-phase6-load-test.php',
        'bin/run-phase6-lock-test.php',
    ];

    /** @var callable(list<string>, string): array{exit_code: int, stdout: string, stderr: string} */
    private $commandRunner;

    /**
     * @param null|callable(list<string>, string): array{exit_code: int, stdout: string, stderr: string} $commandRunner
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly string $outputDirectory,
        ?callable $commandRunner = null,
        private readonly string $composerExecutable = 'composer',
    ) {
        $this->commandRunner = $commandRunner ?? $this->runProcess(...);
    }

    /** @return array{version: string, zip: string, checksum: string, sha256: string, files: int} */
    public function build(): array
    {
        $root = $this->canonicalDirectory($this->projectRoot, 'プロジェクトルート');
        $this->assertGitTreeIsClean($root);
        $version = $this->readVersion($root . '/VERSION');
        $name = 'qr-stamp-rally-' . $version;
        $zipPath = rtrim($this->outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name . '.zip';
        $checksumPath = $zipPath . '.sha256';
        $this->assertOutputIsAvailable($zipPath, $checksumPath);

        $workRoot = sys_get_temp_dir() . '/qr-rally-release-' . bin2hex(random_bytes(10));
        $stage = $workRoot . '/stage/' . $name;
        $archive = $workRoot . '/' . $name . '.zip';
        $expanded = $workRoot . '/expanded';

        try {
            $this->makeDirectory($stage);
            $this->copyAllowedSources($root, $stage);
            $this->installProductionDependencies($stage);
            $this->assertNoSymlinks($stage);
            $this->assertNoForbiddenPaths($stage);
            $this->assertProductionDependencies($root, $stage);
            $this->assertLicensesExist($stage);
            $this->assertPhpSyntax($stage);
            $this->runAutomatedTests($root);
            $this->assertAutoloadWorks($stage);

            $expected = $this->fileHashes($stage);
            $this->createArchive($stage, $name, $archive);
            $this->extractAndInspect($archive, $expanded, $name);
            $actual = $this->fileHashes($expanded . '/' . $name);
            if ($actual !== $expected) {
                throw new ReleaseBuildException('ZIP再展開後のファイル一覧またはSHA-256が一致しません。');
            }

            $sha256 = hash_file('sha256', $archive);
            if (!is_string($sha256)) {
                throw new ReleaseBuildException('ZIPのSHA-256を計算できません。');
            }
            $checksumContents = $sha256 . '  ' . basename($zipPath) . "\n";

            $this->makeDirectory($this->outputDirectory);
            if (!rename($archive, $zipPath)) {
                throw new ReleaseBuildException('配布ZIPをdistへ移動できません。');
            }
            if (file_put_contents($checksumPath, $checksumContents, LOCK_EX) === false) {
                @unlink($zipPath);
                throw new ReleaseBuildException('チェックサムファイルを作成できません。');
            }

            return [
                'version' => $version,
                'zip' => $zipPath,
                'checksum' => $checksumPath,
                'sha256' => $sha256,
                'files' => count($expected),
            ];
        } catch (Throwable $error) {
            if ($error instanceof ReleaseBuildException) {
                throw $error;
            }
            throw new ReleaseBuildException('配布ZIPの生成に失敗しました: ' . $error->getMessage(), 0, $error);
        } finally {
            $this->removeDirectory($workRoot);
        }
    }

    private function assertGitTreeIsClean(string $root): void
    {
        $result = $this->command(['git', 'status', '--porcelain', '--untracked-files=all'], $root);
        if ($result['exit_code'] !== 0) {
            throw new ReleaseBuildException('Git作業ツリーの状態を確認できません: ' . trim($result['stderr']));
        }
        if (trim($result['stdout']) !== '') {
            throw new ReleaseBuildException('未コミットの変更があります。コミットまたは整理してから再実行してください。');
        }
    }

    private function readVersion(string $path): string
    {
        $version = is_file($path) ? trim((string) file_get_contents($path)) : '';
        if (preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D', $version) !== 1) {
            throw new ReleaseBuildException('VERSIONがSemantic Versioning形式ではありません。');
        }
        return $version;
    }

    private function assertOutputIsAvailable(string $zipPath, string $checksumPath): void
    {
        if (file_exists($zipPath) || file_exists($checksumPath)) {
            throw new ReleaseBuildException('同名の成果物がすでに存在します。自動上書きは行いません。');
        }
        if (file_exists($this->outputDirectory) && !is_dir($this->outputDirectory)) {
            throw new ReleaseBuildException('distの生成先がディレクトリではありません。');
        }
        if (is_link($this->outputDirectory)) {
            throw new ReleaseBuildException('distの生成先にシンボリックリンクは使用できません。');
        }
    }

    private function copyAllowedSources(string $root, string $stage): void
    {
        foreach (self::ALLOWED_DIRECTORIES as $relative) {
            $source = $root . '/' . $relative;
            if (!is_dir($source)) {
                throw new ReleaseBuildException("必須ディレクトリがありません: {$relative}");
            }
            $this->copyDirectory($source, $stage . '/' . $relative);
        }
        foreach (self::ALLOWED_FILES as $relative) {
            $source = $root . '/' . $relative;
            if (!is_file($source) || is_link($source)) {
                throw new ReleaseBuildException("必須ファイルがないかシンボリックリンクです: {$relative}");
            }
            $this->copyFile($source, $stage . '/' . $relative);
        }
    }

    private function installProductionDependencies(string $stage): void
    {
        $validation = $this->command([
            PHP_BINARY,
            $this->composerExecutable,
            'validate',
            '--no-check-publish',
            '--strict',
        ], $stage);
        if ($validation['exit_code'] !== 0) {
            throw new ReleaseBuildException('composer.jsonとcomposer.lockの検証に失敗しました: ' . trim($validation['stderr']));
        }
        $result = $this->command([
            PHP_BINARY,
            $this->composerExecutable,
            'install',
            '--no-dev',
            '--prefer-dist',
            '--no-interaction',
            '--no-progress',
            '--no-scripts',
            '--optimize-autoloader',
        ], $stage);
        if ($result['exit_code'] !== 0) {
            throw new ReleaseBuildException('Composer本番依存の構築に失敗しました: ' . trim($result['stderr']));
        }
    }

    private function assertProductionDependencies(string $root, string $stage): void
    {
        $installedPath = $stage . '/vendor/composer/installed.json';
        if (!is_file($stage . '/vendor/autoload.php') || !is_file($installedPath)) {
            throw new ReleaseBuildException('Composer本番依存またはautoload.phpがありません。');
        }
        $lock = $this->decodeJson($root . '/composer.lock');
        $installed = $this->decodeJson($installedPath);
        $installedPackages = isset($installed['packages']) && is_array($installed['packages'])
            ? $installed['packages']
            : (array_is_list($installed) ? $installed : []);
        $installedNames = [];
        foreach ($installedPackages as $package) {
            if (is_array($package) && isset($package['name']) && is_string($package['name'])) {
                $installedNames[] = $package['name'];
            }
        }
        $productionNames = array_map(
            static fn (array $package): string => (string) $package['name'],
            is_array($lock['packages'] ?? null) ? $lock['packages'] : [],
        );
        $developmentNames = array_map(
            static fn (array $package): string => (string) $package['name'],
            is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [],
        );
        sort($installedNames);
        sort($productionNames);
        if ($installedNames !== $productionNames) {
            throw new ReleaseBuildException('vendorのパッケージ一覧がcomposer.lockの本番依存と一致しません。');
        }
        if (array_intersect($installedNames, $developmentNames) !== []) {
            throw new ReleaseBuildException('vendorへ開発依存が混入しています。');
        }
    }

    private function assertLicensesExist(string $stage): void
    {
        foreach (self::REQUIRED_VENDOR_LICENSES as $relative) {
            if (!is_file($stage . '/' . $relative)) {
                throw new ReleaseBuildException("第三者ライセンスがありません: {$relative}");
            }
        }
    }

    private function assertPhpSyntax(string $stage): void
    {
        foreach (array_keys($this->fileHashes($stage)) as $relative) {
            if (!str_ends_with($relative, '.php')) {
                continue;
            }
            $result = $this->command([PHP_BINARY, '-l', $stage . '/' . $relative], $stage);
            if ($result['exit_code'] !== 0) {
                throw new ReleaseBuildException("PHP構文検査に失敗しました: {$relative}\n" . trim($result['stderr']));
            }
        }
    }

    private function runAutomatedTests(string $root): void
    {
        $phpunit = $root . '/vendor/bin/phpunit';
        if (!is_file($phpunit)) {
            throw new ReleaseBuildException('自動テスト実行用のvendor/bin/phpunitがありません。');
        }
        $result = $this->command([PHP_BINARY, $phpunit, '--colors=never'], $root);
        if ($result['exit_code'] !== 0) {
            throw new ReleaseBuildException('自動テストが失敗しました: ' . trim($result['stdout'] . "\n" . $result['stderr']));
        }
    }

    private function assertAutoloadWorks(string $stage): void
    {
        $code = 'require ' . var_export($stage . '/vendor/autoload.php', true) . ';';
        $result = $this->command([PHP_BINARY, '-r', $code], $stage);
        if ($result['exit_code'] !== 0) {
            throw new ReleaseBuildException('配布物のvendor/autoload.phpを読み込めません。');
        }
    }

    private function assertNoForbiddenPaths(string $stage): void
    {
        foreach (self::FORBIDDEN_PATHS as $relative) {
            if (file_exists($stage . '/' . $relative) || is_link($stage . '/' . $relative)) {
                throw new ReleaseBuildException("配布禁止対象が混入しています: {$relative}");
            }
        }
        foreach (array_keys($this->fileHashes($stage)) as $relative) {
            $basename = basename($relative);
            if ($basename === '.DS_Store' || str_ends_with($relative, '.sqlite')
                || str_contains($relative, '.sqlite-') || str_ends_with($relative, '.log')) {
                throw new ReleaseBuildException("実行時生成物が混入しています: {$relative}");
            }
        }
    }

    private function assertNoSymlinks(string $root): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new ReleaseBuildException('シンボリックリンクは配布できません: ' . $item->getPathname());
            }
        }
    }

    private function createArchive(string $stage, string $topDirectory, string $archive): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new ReleaseBuildException('配布ZIPを作成できません。');
        }
        try {
            if (!$zip->addEmptyDir($topDirectory)) {
                throw new ReleaseBuildException('ZIPへトップフォルダを追加できません。');
            }
            foreach (array_keys($this->fileHashes($stage)) as $relative) {
                if (!$zip->addFile($stage . '/' . $relative, $topDirectory . '/' . $relative)) {
                    throw new ReleaseBuildException("ZIPへファイルを追加できません: {$relative}");
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function extractAndInspect(string $archive, string $expanded, string $topDirectory): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new ReleaseBuildException('生成したZIPを再度開けません。');
        }
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || str_contains($name, '\\') || str_starts_with($name, '/')
                    || preg_match('#(^|/)\.\.(/|$)#', $name) === 1
                    || ($name !== $topDirectory . '/' && !str_starts_with($name, $topDirectory . '/'))) {
                    throw new ReleaseBuildException('ZIP内に不正なパスがあります。');
                }
                $attributes = $zip->getExternalAttributesIndex($index, $operationsSystem, $externalAttributes);
                if ($attributes && (($externalAttributes >> 16) & 0170000) === 0120000) {
                    throw new ReleaseBuildException('ZIP内にシンボリックリンクがあります。');
                }
            }
            $this->makeDirectory($expanded);
            if (!$zip->extractTo($expanded)) {
                throw new ReleaseBuildException('ZIPを検証用ディレクトリへ展開できません。');
            }
        } finally {
            $zip->close();
        }
        $this->assertNoSymlinks($expanded);
    }

    /** @return array<string, string> */
    private function fileHashes(string $root): array
    {
        $hashes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            $hash = hash_file('sha256', $item->getPathname());
            if (!is_string($hash)) {
                throw new ReleaseBuildException("SHA-256を計算できません: {$relative}");
            }
            $hashes[$relative] = $hash;
        }
        ksort($hashes);
        return $hashes;
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (is_link($source)) {
            throw new ReleaseBuildException("シンボリックリンクはコピーできません: {$source}");
        }
        $this->makeDirectory($destination);
        $iterator = new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            $target = $destination . '/' . $item->getFilename();
            if ($item->isLink()) {
                throw new ReleaseBuildException("シンボリックリンクはコピーできません: {$item->getPathname()}");
            }
            if ($item->isDir()) {
                $this->copyDirectory($item->getPathname(), $target);
            } elseif ($item->isFile()) {
                $this->copyFile($item->getPathname(), $target);
            }
        }
    }

    private function copyFile(string $source, string $destination): void
    {
        $this->makeDirectory(dirname($destination));
        if (!copy($source, $destination)) {
            throw new ReleaseBuildException("ファイルをコピーできません: {$source}");
        }
    }

    private function makeDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ReleaseBuildException("ディレクトリを作成できません: {$directory}");
        }
    }

    private function canonicalDirectory(string $path, string $label): string
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new ReleaseBuildException("{$label}が見つかりません。");
        }
        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $path): array
    {
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($decoded)) {
            throw new ReleaseBuildException("JSONを読み取れません: {$path}");
        }
        return $decoded;
    }

    /** @param list<string> $command
     *  @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function command(array $command, string $workingDirectory): array
    {
        return ($this->commandRunner)($command, $workingDirectory);
    }

    /** @param list<string> $command
     *  @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, string $workingDirectory): array
    {
        $stdoutStream = tmpfile();
        $stderrStream = tmpfile();
        if ($stdoutStream === false || $stderrStream === false) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => '一時出力ファイルを作成できません。'];
        }
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => $stdoutStream,
            2 => $stderrStream,
        ];
        $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            fclose($stdoutStream);
            fclose($stderrStream);
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'プロセスを開始できません。'];
        }
        fclose($pipes[0]);
        $exitCode = proc_close($process);
        rewind($stdoutStream);
        rewind($stderrStream);
        $stdout = stream_get_contents($stdoutStream);
        $stderr = stream_get_contents($stderrStream);
        fclose($stdoutStream);
        fclose($stderrStream);
        return [
            'exit_code' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
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
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($directory);
    }
}
