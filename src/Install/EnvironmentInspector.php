<?php

declare(strict_types=1);

namespace QrRally\Install;

use PDO;
use PDOException;
use SQLite3;
use Throwable;

final class EnvironmentInspector
{
    private const REQUIRED_EXTENSIONS = [
        'pdo_sqlite', 'sqlite3', 'mbstring', 'dom', 'session', 'filter', 'hash', 'fileinfo', 'zip',
    ];

    public function __construct(private readonly string $applicationRoot)
    {
    }

    /** @param array<string, mixed> $server
     *  @return array{php: bool, extensions: array<string, bool>, recommended_extensions: array<string, bool>, optional_extensions: array<string, bool>, https: bool, requirements_met: bool}
     */
    public function basic(array $server): array
    {
        $extensions = [];
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $extensions[$extension] = extension_loaded($extension);
        }
        $php = version_compare(PHP_VERSION, '8.3.0', '>=');
        return [
            'php' => $php,
            'extensions' => $extensions,
            'recommended_extensions' => ['zlib' => extension_loaded('zlib')],
            'optional_extensions' => ['gd' => extension_loaded('gd')],
            'https' => $this->isHttps($server),
            'requirements_met' => $php && !in_array(false, $extensions, true),
        ];
    }

    /** @param array<string, mixed> $server */
    public function candidateBaseUrl(array $server): string
    {
        $https = $this->isHttps($server);
        $host = (string) ($server['HTTP_HOST'] ?? 'localhost');
        if (preg_match('/[\x00-\x20\x7f]/', $host) || preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $host) !== 1) {
            $host = 'localhost';
        }
        $script = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? '/public/install.php'));
        $basePath = preg_replace('#/(?:public/)?install\.php$#', '', $script) ?? '';
        return ($https ? 'https' : 'http') . '://' . $host . '/' . trim($basePath, '/') . ($basePath === '' ? '' : '/');
    }

    /** @param array<string, mixed> $server */
    public function candidatePrivatePath(array $server): string
    {
        $documentRoot = (string) ($server['DOCUMENT_ROOT'] ?? '');
        if ($documentRoot !== '' && $this->isAbsolutePath($documentRoot)) {
            $candidate = rtrim(dirname($documentRoot), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'qr-sr-private';
            if ($this->isOutsidePublicArea($candidate, $server)) {
                return $candidate;
            }
        }
        return dirname($this->applicationRoot) . DIRECTORY_SEPARATOR . 'qr-sr-private';
    }

    /** @param array<string, mixed> $server
     *  @return array<string, string>
     */
    public function validateInput(InstallInput $input, array $server): array
    {
        $errors = [];
        if (!in_array($input->environment, ['development', 'production'], true)) {
            $errors['environment'] = '実行環境を選択してください。';
        }
        $url = parse_url($input->baseUrl);
        if ($url === false || !isset($url['scheme'], $url['host'])
            || !in_array($url['scheme'], ['http', 'https'], true)
            || isset($url['user'], $url['pass'], $url['query'], $url['fragment'])
            || !str_ends_with($input->baseUrl, '/') || preg_match('/[\x00-\x1f\x7f]/', $input->baseUrl)) {
            $errors['base_url'] = 'ベースURLを、末尾が / の完全なHTTPまたはHTTPS URLで入力してください。';
        } elseif ($input->environment === 'production' && $url['scheme'] !== 'https') {
            $errors['base_url'] = '本番環境ではHTTPSのURLが必要です。';
        } elseif ($input->environment === 'production' && !$this->isHttps($server)) {
            $errors['base_url'] = '本番環境のセットアップ画面へHTTPSでアクセスしてください。';
        } elseif ($url['scheme'] === 'http' && !$this->isDevelopmentHost((string) $url['host'])) {
            $errors['base_url'] = 'HTTPはlocalhost、ループバック、LAN内IPの開発環境だけで使用できます。';
        } elseif ($url['scheme'] === 'http'
            && !$this->isDevelopmentHost((string) (parse_url($this->candidateBaseUrl($server), PHP_URL_HOST) ?: ''))) {
            $errors['base_url'] = '現在のアクセス元がローカル・LAN内ではないため、HTTPではインストールできません。';
        } elseif ((string) ($url['path'] ?? '/') !== (string) (parse_url($this->candidateBaseUrl($server), PHP_URL_PATH) ?: '/')) {
            $errors['base_url'] = 'ベースURLのパスを、現在の設置先URLに合わせてください。';
        }
        if (!$this->isAbsolutePath($input->privatePath)) {
            $errors['private_path'] = '非公開領域はサーバー上の絶対パスで入力してください。';
        } elseif (!$this->isOutsidePublicArea($input->privatePath, $server)) {
            $errors['private_path'] = '非公開領域には、公開領域の外側にあるパスを指定してください。';
        }
        if (!in_array($input->timezone, timezone_identifiers_list(), true)) {
            $errors['timezone'] = '有効なタイムゾーンを選択してください。';
        }
        return $errors;
    }

    /** @param array<string, mixed> $server
     *  @return array{directory: bool, file: bool, sqlite: bool, wal: bool, lock: bool, cleanup: bool}
     */
    public function inspectPrivatePath(string $path, array $server): array
    {
        if (!$this->isAbsolutePath($path) || !$this->isOutsidePublicArea($path, $server)) {
            throw new InstallException('非公開領域のパスが安全ではありません。');
        }
        $createdRoot = false;
        if (!is_dir($path)) {
            if (!mkdir($path, 0700, true) && !is_dir($path)) {
                throw new InstallException('非公開領域を作成できません。');
            }
            $createdRoot = true;
        }
        if (is_link($path) || !is_writable($path)) {
            throw new InstallException('非公開領域がシンボリックリンク、または書き込み不可です。');
        }

        $probe = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.install-probe-' . bin2hex(random_bytes(8));
        $databasePath = $probe . '.sqlite';
        $result = ['directory' => true, 'file' => false, 'sqlite' => false, 'wal' => false, 'lock' => false, 'cleanup' => false];
        try {
            $payload = bin2hex(random_bytes(16));
            if (file_put_contents($probe, $payload, LOCK_EX) === false || file_get_contents($probe) !== $payload) {
                throw new InstallException('非公開領域でファイルの作成と読み取りができません。');
            }
            $result['file'] = true;
            $first = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $first->exec('PRAGMA journal_mode = WAL');
            $first->exec('CREATE TABLE lock_probe (id INTEGER PRIMARY KEY, value TEXT) STRICT');
            $result['sqlite'] = true;
            $result['wal'] = strtolower((string) $first->query('PRAGMA journal_mode')->fetchColumn()) === 'wal';
            $second = new SQLite3($databasePath, SQLITE3_OPEN_READWRITE);
            $second->busyTimeout(20);
            $first->exec('BEGIN IMMEDIATE');
            try {
                if (@$second->exec("INSERT INTO lock_probe (value) VALUES ('test')") === false) {
                    $result['lock'] = true;
                }
            } catch (Throwable) {
                $result['lock'] = true;
            } finally {
                $first->exec('ROLLBACK');
                $second->close();
            }
            if (!$result['wal'] || !$result['lock']) {
                throw new InstallException('SQLiteのWALまたはロック動作を確認できません。');
            }
        } catch (PDOException $error) {
            throw new InstallException('SQLiteの診断に失敗しました。', [], $error);
        } finally {
            unset($first);
            @unlink($probe);
            @unlink($databasePath);
            @unlink($databasePath . '-wal');
            @unlink($databasePath . '-shm');
            $result['cleanup'] = !file_exists($probe) && !file_exists($databasePath)
                && !file_exists($databasePath . '-wal') && !file_exists($databasePath . '-shm');
            if ($createdRoot) {
                @rmdir($path);
            }
        }
        if (!$result['cleanup']) {
            throw new InstallException('診断用ファイルを削除できません。');
        }
        return $result;
    }

    /** @param array<string, mixed> $server */
    public function isHttps(array $server): bool
    {
        return in_array(strtolower((string) ($server['HTTPS'] ?? '')), ['on', '1'], true)
            || (string) ($server['SERVER_PORT'] ?? '') === '443';
    }

    private function isDevelopmentHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_match('/^127\./', $host) === 1 || preg_match('/^10\./', $host) === 1
                || preg_match('/^192\.168\./', $host) === 1
                || preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host) === 1;
        }
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            && (str_starts_with($host, 'fc') || str_starts_with($host, 'fd') || str_starts_with($host, 'fe8') || str_starts_with($host, 'fe9') || str_starts_with($host, 'fea') || str_starts_with($host, 'feb'));
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR);
    }

    /** @param array<string, mixed> $server */
    private function isOutsidePublicArea(string $path, array $server): bool
    {
        $candidate = $this->normalizeFuturePath($path);
        if ($candidate === null) {
            return false;
        }
        $roots = [$this->applicationRoot, (string) ($server['DOCUMENT_ROOT'] ?? '')];
        foreach ($roots as $root) {
            if ($root === '') {
                continue;
            }
            $normalizedRoot = $this->normalizeFuturePath($root);
            if ($normalizedRoot !== null
                && ($candidate === $normalizedRoot || str_starts_with($candidate, $normalizedRoot . DIRECTORY_SEPARATOR))) {
                return false;
            }
        }
        return true;
    }

    private function normalizeFuturePath(string $path): ?string
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $suffix = [];
        $cursor = $path;
        while (!file_exists($cursor)) {
            $name = basename($cursor);
            if ($name === '' || $name === '.' || $name === '..') {
                return null;
            }
            array_unshift($suffix, $name);
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                return null;
            }
            $cursor = $parent;
        }
        $real = realpath($cursor);
        if ($real === false || is_link($cursor)) {
            return null;
        }
        return $real . ($suffix === [] ? '' : DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $suffix));
    }
}
