<?php

declare(strict_types=1);

namespace QrRally\Backup;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use QrRally\Database\ConnectionFactory;
use SQLite3;
use Throwable;
use ZipArchive;
use Closure;

final class BackupManager
{
    public const PRODUCT = 'qr-stamp-rally';
    public const FORMAT_VERSION = 1;
    public const MAX_ZIP_BYTES = 100 * 1024 * 1024;
    public const MAX_DATABASE_BYTES = 250 * 1024 * 1024;

    private const REQUIRED_TABLES = [
        'admins', 'app_settings', 'events', 'application_fields', 'spots', 'participants',
        'stamp_acquisitions', 'applications', 'audit_logs', 'backup_operations',
        'admin_login_attempts', 'schema_migrations',
    ];

    public function __construct(
        private readonly string $databasePath,
        private readonly string $workRoot,
        private readonly string $baseUrl,
        private readonly int $busyTimeoutMs = 5000,
        private readonly ?Closure $beforeSwitchForTesting = null,
    ) {
    }

    /** @return array{path: string, filename: string, hash: string, manifest: array<string, mixed>} */
    public function create(): array
    {
        $directory = $this->newWorkDirectory('backup');
        $database = $directory . '/database.sqlite';
        $zipPath = $directory . '/backup.zip';
        try {
            $this->onlineBackup($this->databasePath, $database);
            $pdo = $this->openReadOnly($database);
            $this->assertIntegrity($pdo);
            $manifest = $this->manifest($pdo, $database);
            file_put_contents($directory . '/manifest.json', json_encode(
                $manifest,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true
                || !$zip->addFile($directory . '/manifest.json', 'manifest.json')
                || !$zip->addFile($database, 'database.sqlite') || !$zip->close()) {
                throw new BackupException('バックアップZIPを作成できません。');
            }
            if ((int) filesize($zipPath) > self::MAX_ZIP_BYTES) {
                throw new BackupException('バックアップが100MBを超えています。');
            }
            $timestamp = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d_His');
            return [
                'path' => $zipPath,
                'filename' => "qr-rally-backup_{$timestamp}.zip",
                'hash' => hash_file('sha256', $zipPath),
                'manifest' => $manifest,
            ];
        } catch (Throwable $error) {
            $this->removeDirectory($directory);
            throw $error instanceof BackupException ? $error : new BackupException('バックアップを作成できません。', 0, $error);
        }
    }

    /** @return array{work_directory: string, database_path: string, archive_hash: string, manifest: array<string, mixed>} */
    public function validateUploaded(string $uploadedPath, int $size): array
    {
        if ($size < 1 || $size > self::MAX_ZIP_BYTES || !is_file($uploadedPath)) {
            throw new BackupException('100MB以下のバックアップZIPを選択してください。');
        }
        $directory = $this->newWorkDirectory('restore');
        $databasePath = $directory . '/database.sqlite';
        try {
            $zip = new ZipArchive();
            if ($zip->open($uploadedPath) !== true) {
                throw new BackupException('ZIPファイルを開けません。');
            }
            $this->assertArchiveEntries($zip);
            $this->copyEntry($zip, 'manifest.json', $directory . '/manifest.json', 1024 * 1024);
            $this->copyEntry($zip, 'database.sqlite', $databasePath, self::MAX_DATABASE_BYTES);
            $zip->close();
            $manifest = $this->decodeManifest($directory . '/manifest.json');
            $this->assertManifest($manifest, $databasePath);
            $pdo = $this->openReadOnly($databasePath);
            $this->assertIntegrity($pdo);
            $this->assertRequiredTables($pdo);
            $this->assertCounts($pdo, $manifest['table_counts']);
            $this->assertForeignKeys($pdo);
            $this->assertCompatibility($pdo, $manifest);
            return [
                'work_directory' => $directory,
                'database_path' => $databasePath,
                'archive_hash' => hash_file('sha256', $uploadedPath),
                'manifest' => $manifest,
            ];
        } catch (Throwable $error) {
            $this->removeDirectory($directory);
            throw $error instanceof BackupException ? $error : new BackupException('バックアップを検証できません。', 0, $error);
        }
    }

    /** @param array<string, mixed> $manifest */
    public function restore(string $candidatePath, array $manifest, RestoreStateStore $states, int $adminId): void
    {
        if (!is_file($candidatePath) || dirname($candidatePath) === dirname($this->databasePath)) {
            throw new BackupException('復元用データが見つかりません。');
        }
        $rollback = $this->databasePath . '.restore-backup-' . bin2hex(random_bytes(6));
        $displaced = $this->databasePath . '.restore-old-' . bin2hex(random_bytes(6));
        $states->write(['operation_id' => bin2hex(random_bytes(16)), 'status' => 'switching', 'maintenance' => true,
            'work_directory' => dirname($candidatePath), 'error_summary' => null]);
        try {
            $this->onlineBackup($this->databasePath, $rollback);
            $this->checkpoint();
            if ($this->beforeSwitchForTesting !== null) {
                ($this->beforeSwitchForTesting)();
            }
            if (!rename($this->databasePath, $displaced) || !rename($candidatePath, $this->databasePath)) {
                throw new BackupException('データベースを切り替えられません。');
            }
            $restored = (new ConnectionFactory())->connect($this->databasePath, $this->busyTimeoutMs);
            $this->assertIntegrity($restored);
            $this->assertForeignKeys($restored);
            $statement = $restored->prepare(
                "INSERT INTO backup_operations (operation_type, status, backup_version, file_hash, created_at, updated_at) "
                . "VALUES ('restore', 'completed', :version, :hash, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))",
            );
            $statement->execute(['version' => (string) self::FORMAT_VERSION, 'hash' => (string) $manifest['database_sha256']]);
            $log = $restored->prepare(
                "INSERT INTO audit_logs (event_type, actor_type, actor_id, result, context_json, created_at) "
                . "VALUES ('backup.restore', 'admin', :admin_id, 'success', :context, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))",
            );
            $log->execute(['admin_id' => $adminId, 'context' => json_encode(['source_created_at' => $manifest['created_at']], JSON_THROW_ON_ERROR)]);
            unset($restored);
            @unlink($displaced);
            @unlink($rollback);
            $this->removeDirectory(dirname($candidatePath));
            $states->write([
                'operation_id' => bin2hex(random_bytes(16)),
                'status' => 'completed',
                'maintenance' => false,
                'error_summary' => null,
            ]);
        } catch (Throwable $error) {
            if (is_file($displaced)) {
                if (is_file($this->databasePath)) {
                    @unlink($this->databasePath);
                }
                @unlink($this->databasePath . '-wal');
                @unlink($this->databasePath . '-shm');
                @rename($displaced, $this->databasePath);
            } elseif (!is_file($this->databasePath) && is_file($rollback)) {
                @rename($rollback, $this->databasePath);
            }
            @unlink($rollback);
            $states->write(['operation_id' => bin2hex(random_bytes(16)), 'status' => 'failed', 'maintenance' => false,
                'work_directory' => dirname($candidatePath), 'error_summary' => 'データベースの切り替えに失敗しました。']);
            throw $error instanceof BackupException ? $error : new BackupException('復元に失敗したため、元のデータを維持しました。', 0, $error);
        }
    }

    public function cleanup(string $directory): void
    {
        $this->removeDirectory($directory);
    }

    public function databaseSize(): string
    {
        $bytes = @filesize($this->databasePath);
        return is_int($bytes) ? number_format($bytes / 1024 / 1024, 1) . ' MB' : '不明';
    }

    private function onlineBackup(string $sourcePath, string $destinationPath): void
    {
        $source = new SQLite3($sourcePath, SQLITE3_OPEN_READONLY);
        $destination = new SQLite3($destinationPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $source->busyTimeout($this->busyTimeoutMs);
        if (!$source->backup($destination)) {
            throw new BackupException('SQLiteオンラインバックアップに失敗しました。');
        }
        $destination->close();
        $source->close();
        @chmod($destinationPath, 0600);
    }

    private function checkpoint(): void
    {
        $db = new SQLite3($this->databasePath, SQLITE3_OPEN_READWRITE);
        $db->busyTimeout($this->busyTimeoutMs);
        $result = $db->querySingle('PRAGMA wal_checkpoint(TRUNCATE)', true);
        $db->close();
        if (!is_array($result) || (int) ($result['busy'] ?? 1) !== 0) {
            throw new BackupException('利用中の処理があるため、データベースを切り替えられません。時間をおいて再実行してください。');
        }
    }

    /** @return array<string, mixed> */
    private function manifest(PDO $database, string $path): array
    {
        $settings = $this->settings($database);
        $counts = [];
        foreach ($this->tableNames($database) as $table) {
            $counts[$table] = (int) $database->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $table) . '"')->fetchColumn();
        }
        return [
            'product' => self::PRODUCT,
            'backup_version' => self::FORMAT_VERSION,
            'product_version' => $settings['product_version'] ?? '',
            'schema_version' => $settings['schema_version'] ?? '',
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'database_file' => 'database.sqlite',
            'database_sha256' => hash_file('sha256', $path),
            'table_counts' => $counts,
            'source_base_url' => $this->baseUrl,
        ];
    }

    /** @return array<string, mixed> */
    private function decodeManifest(string $path): array
    {
        $json = (string) file_get_contents($path);
        preg_match_all('/"((?:\\\\.|[^"\\\\])*)"\s*:/u', $json, $matches);
        $keys = [];
        foreach ($matches[1] ?? [] as $encodedKey) {
            $key = json_decode('"' . $encodedKey . '"', true, 2, JSON_THROW_ON_ERROR);
            if (!is_string($key) || isset($keys[$key])) {
                throw new BackupException('manifest.jsonに重複したキーがあります。');
            }
            $keys[$key] = true;
        }
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new BackupException('manifest.jsonが不正です。');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $manifest */
    private function assertManifest(array $manifest, string $databasePath): void
    {
        $required = ['product', 'backup_version', 'product_version', 'schema_version', 'created_at', 'database_file', 'database_sha256', 'table_counts', 'source_base_url'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $manifest)) {
                throw new BackupException("マニフェストに{$key}がありません。");
            }
        }
        if (!is_string($manifest['product']) || !is_int($manifest['backup_version'])
            || !is_string($manifest['product_version']) || !is_string($manifest['schema_version'])
            || !is_string($manifest['created_at']) || !is_string($manifest['database_file'])
            || !is_string($manifest['source_base_url']) || filter_var($manifest['source_base_url'], FILTER_VALIDATE_URL) === false) {
            throw new BackupException('マニフェストの値が不正です。');
        }
        try {
            new DateTimeImmutable($manifest['created_at']);
        } catch (Throwable) {
            throw new BackupException('バックアップ作成日時が不正です。');
        }
        if ($manifest['product'] !== self::PRODUCT || $manifest['backup_version'] !== self::FORMAT_VERSION
            || $manifest['database_file'] !== 'database.sqlite' || !is_array($manifest['table_counts'])) {
            throw new BackupException('この製品またはバージョンのバックアップではありません。');
        }
        if (!is_string($manifest['database_sha256']) || !hash_equals($manifest['database_sha256'], hash_file('sha256', $databasePath))) {
            throw new BackupException('データベースのハッシュが一致しません。');
        }
    }

    private function assertArchiveEntries(ZipArchive $zip): void
    {
        if ($zip->numFiles !== 2) {
            throw new BackupException('ZIPには指定された2ファイルだけを含めてください。');
        }
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if ($name === false || str_contains($name, "\0") || str_contains($name, '/') || str_contains($name, '\\')
                || $name === '.' || $name === '..') {
                throw new BackupException('ZIP内に不正なパスがあります。');
            }
            if (isset($names[$name])) {
                throw new BackupException('ZIP内に同名ファイルがあります。');
            }
            $names[$name] = true;
            if ($zip->getExternalAttributesIndex($index, $operations, $attributes)
                && (($attributes >> 16) & 0170000) === 0120000) {
                throw new BackupException('シンボリックリンクは使用できません。');
            }
        }
        if (array_keys($names) !== ['manifest.json', 'database.sqlite'] && array_keys($names) !== ['database.sqlite', 'manifest.json']) {
            throw new BackupException('ZIP内のファイル構成が正しくありません。');
        }
    }

    private function copyEntry(ZipArchive $zip, string $name, string $destination, int $limit): void
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw new BackupException("{$name}を読み取れません。");
        }
        $output = fopen($destination, 'xb');
        if ($output === false) {
            fclose($stream);
            throw new BackupException('一時ファイルを作成できません。');
        }
        $written = stream_copy_to_stream($stream, $output, $limit + 1);
        fclose($output);
        fclose($stream);
        if ($written === false || $written > $limit) {
            throw new BackupException("{$name}が許容サイズを超えています。");
        }
        @chmod($destination, 0600);
    }

    private function openReadOnly(string $path): PDO
    {
        return new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    private function assertIntegrity(PDO $database): void
    {
        if ($database->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new BackupException('SQLiteデータベースが破損しています。');
        }
    }

    private function assertForeignKeys(PDO $database): void
    {
        if ($database->query('PRAGMA foreign_key_check')->fetch() !== false) {
            throw new BackupException('データの関連に不整合があります。');
        }
    }

    private function assertRequiredTables(PDO $database): void
    {
        $tables = $this->tableNames($database);
        foreach (self::REQUIRED_TABLES as $required) {
            if (!in_array($required, $tables, true)) {
                throw new BackupException("必須テーブル{$required}がありません。");
            }
        }
    }

    /** @param array<string, mixed> $expected */
    private function assertCounts(PDO $database, array $expected): void
    {
        $expectedNames = array_keys($expected);
        sort($expectedNames, SORT_STRING);
        if ($expectedNames !== $this->tableNames($database)) {
            throw new BackupException('テーブル件数情報が不足しています。');
        }
        foreach ($expected as $table => $count) {
            if (!is_string($table) || !is_int($count) || !in_array($table, $this->tableNames($database), true)) {
                throw new BackupException('テーブル件数情報が不正です。');
            }
            $actual = (int) $database->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $table) . '"')->fetchColumn();
            if ($actual !== $count) {
                throw new BackupException("{$table}の件数が一致しません。");
            }
        }
    }

    /** @param array<string, mixed> $manifest */
    private function assertCompatibility(PDO $database, array $manifest): void
    {
        $current = $this->openReadOnly($this->databasePath);
        $currentSettings = $this->settings($current);
        $restoredSettings = $this->settings($database);
        if (($manifest['schema_version'] ?? null) !== ($restoredSettings['schema_version'] ?? null)
            || ($restoredSettings['schema_version'] ?? null) !== ($currentSettings['schema_version'] ?? null)) {
            throw new BackupException('DBスキーマのバージョンに互換性がありません。');
        }
        $source = preg_replace('/[^0-9.].*$/', '', (string) ($manifest['product_version'] ?? '')) ?: '0.0.0';
        $currentVersion = preg_replace('/[^0-9.].*$/', '', (string) ($currentSettings['product_version'] ?? '')) ?: '0.0.0';
        if (version_compare($source, $currentVersion, '>')) {
            throw new BackupException('新しい製品バージョンのバックアップは復元できません。');
        }
    }

    /** @return array<string, string> */
    private function settings(PDO $database): array
    {
        $settings = [];
        foreach ($database->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll() as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        return $settings;
    }

    /** @return list<string> */
    private function tableNames(PDO $database): array
    {
        return array_values(array_map('strval', $database->query(
            "SELECT name FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        )->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function newWorkDirectory(string $prefix): string
    {
        if (!is_dir($this->workRoot) && !mkdir($this->workRoot, 0700, true) && !is_dir($this->workRoot)) {
            throw new BackupException('一時保存先を作成できません。');
        }
        $directory = $this->workRoot . '/' . $prefix . '-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new BackupException('一時作業ディレクトリを作成できません。');
        }
        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        $root = realpath($this->workRoot);
        $real = realpath($directory);
        if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return;
        }
        foreach (glob($real . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($real);
    }
}
