<?php

declare(strict_types=1);

namespace QrRally\Ops\Demo;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class DemoDatabaseResetter
{
    private const REQUIRED_TABLES = [
        'admins', 'app_settings', 'events', 'application_fields', 'spots',
        'participants', 'stamp_acquisitions', 'applications', 'audit_logs',
    ];

    public function __construct(
        private readonly string $databasePath,
        private readonly string $baselinePath,
        private readonly string $statePath,
        private readonly string $lockPath,
        private readonly string $logPath,
        private readonly string $timezone,
    ) {
    }

    public function reset(?DateTimeImmutable $now = null): void
    {
        $this->ensureDirectory(dirname($this->lockPath));
        $lock = fopen($this->lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('デモ初期化はすでに実行中です。');
        }

        $candidate = $this->databasePath . '.demo-candidate-' . bin2hex(random_bytes(6));
        $rollback = $this->databasePath . '.demo-rollback-' . bin2hex(random_bytes(6));
        try {
            if (!is_file($this->baselinePath) || !copy($this->baselinePath, $candidate)) {
                throw new RuntimeException('基準DBを一時DBへコピーできません。');
            }
            chmod($candidate, 0600);
            $this->updateDatesAndValidate($candidate, $now ?? new DateTimeImmutable('now'));
            $this->writeState(true, 'switching');
            $this->checkpoint($this->databasePath);
            $this->removeSidecars($this->databasePath);
            if (!rename($this->databasePath, $rollback) || !rename($candidate, $this->databasePath)) {
                throw new RuntimeException('デモDBを原子的に差し替えられません。');
            }
            $this->validate($this->databasePath);
            @unlink($rollback);
            $this->cleanupRestoreWorkDirectories();
            $this->writeState(false, 'completed');
            $this->log('completed');
        } catch (Throwable $error) {
            if (is_file($rollback)) {
                @unlink($this->databasePath);
                $this->removeSidecars($this->databasePath);
                @rename($rollback, $this->databasePath);
            }
            @unlink($candidate);
            $this->writeState(!is_file($this->databasePath), 'failed');
            $this->log('failed', $error->getMessage());
            throw $error;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function captureBaseline(): void
    {
        $this->writeState(true, 'capturing');
        try {
            $this->checkpoint($this->databasePath);
            $this->validate($this->databasePath);
            $this->ensureDirectory(dirname($this->baselinePath));
            $temporary = $this->baselinePath . '.tmp-' . bin2hex(random_bytes(6));
            if (!copy($this->databasePath, $temporary) || !chmod($temporary, 0400) || !rename($temporary, $this->baselinePath)) {
                @unlink($temporary);
                throw new RuntimeException('基準DBを保存できません。');
            }
            $this->writeState(false, 'baseline-captured');
            $this->log('baseline-captured');
        } catch (Throwable $error) {
            $this->writeState(false, 'capture-failed');
            $this->log('capture-failed', $error->getMessage());
            throw $error;
        }
    }

    private function updateDatesAndValidate(string $path, DateTimeImmutable $now): void
    {
        $local = $now->setTimezone(new DateTimeZone($this->timezone));
        $utc = new DateTimeZone('UTC');
        $startsAt = $local->modify('-1 day')->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $endsAt = $local->modify('+30 days')->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $deadline = $local->modify('+31 days')->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $database = $this->connect($path);
        $statement = $database->prepare('UPDATE events SET starts_at = :starts, ends_at = :ends, application_deadline_at = :deadline, application_enabled = 1, is_paused = 0, updated_at = :updated WHERE id = 1');
        $statement->execute(['starts' => $startsAt, 'ends' => $endsAt, 'deadline' => $deadline, 'updated' => $now->setTimezone($utc)->format('Y-m-d\TH:i:s\Z')]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('基準DBにイベントが1件ありません。');
        }
        $this->assertDatabase($database);
        $database->exec('PRAGMA journal_mode = DELETE');
        unset($database);
    }

    private function validate(string $path): void
    {
        $database = $this->connect($path);
        $this->assertDatabase($database);
        unset($database);
    }

    private function assertDatabase(PDO $database): void
    {
        if (strtolower((string) $database->query('PRAGMA integrity_check')->fetchColumn()) !== 'ok') {
            throw new RuntimeException('SQLite integrity_checkに失敗しました。');
        }
        if ($database->query('PRAGMA foreign_key_check')->fetch() !== false) {
            throw new RuntimeException('SQLite外部キー検査に失敗しました。');
        }
        $tables = $database->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (self::REQUIRED_TABLES as $table) {
            if (!in_array($table, $tables, true)) {
                throw new RuntimeException("必須テーブルがありません: {$table}");
            }
        }
        $counts = [
            'admins' => 1,
            'events' => 1,
            'spots' => 3,
            'participants' => 1,
            'applications' => 1,
        ];
        foreach ($counts as $table => $minimum) {
            $count = (int) $database->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            if ($count < $minimum || ($table === 'spots' && $count !== 3) || ($table === 'events' && $count !== 1)) {
                throw new RuntimeException("基準データの件数が不正です: {$table}");
            }
        }
        if ($database->query('SELECT email FROM admins ORDER BY id LIMIT 1')->fetchColumn() !== 'qr-sr-demo@example.com') {
            throw new RuntimeException('デモ用管理者メールアドレスが一致しません。');
        }
        if ($database->query('SELECT name FROM events WHERE id = 1')->fetchColumn() !== '商店街スタンプラリー') {
            throw new RuntimeException('デモ用イベント名が一致しません。');
        }
        $spotNames = $database->query('SELECT name FROM spots ORDER BY display_order')->fetchAll(PDO::FETCH_COLUMN);
        if ($spotNames !== ['まちの電気屋さん', 'ひだまりベーカリー', 'さくら書店']) {
            throw new RuntimeException('デモ用スポット構成が一致しません。');
        }
    }

    private function checkpoint(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('稼働中DBがありません。');
        }
        $database = $this->connect($path);
        $database->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($database);
    }

    private function connect(string $path): PDO
    {
        $database = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->exec('PRAGMA foreign_keys = ON');
        $database->exec('PRAGMA busy_timeout = 5000');
        return $database;
    }

    private function writeState(bool $maintenance, string $status): void
    {
        $this->ensureDirectory(dirname($this->statePath));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $state = json_encode(['operation_id' => 'demo-reset', 'status' => $status, 'maintenance' => $maintenance, 'started_at' => $now->format(DATE_ATOM), 'updated_at' => $now->format(DATE_ATOM), 'expires_at' => $now->modify('+1 hour')->format(DATE_ATOM)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $temporary = $this->statePath . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $state, LOCK_EX) === false || !chmod($temporary, 0600) || !rename($temporary, $this->statePath)) {
            @unlink($temporary);
            throw new RuntimeException('メンテナンス状態を保存できません。');
        }
    }

    private function log(string $status, ?string $reason = null): void
    {
        $this->ensureDirectory(dirname($this->logPath));
        $line = sprintf("%s status=%s%s\n", gmdate('c'), $status, $reason === null ? '' : ' reason=' . preg_replace('/[\r\n]+/', ' ', $reason));
        file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }

    private function removeSidecars(string $path): void
    {
        @unlink($path . '-wal');
        @unlink($path . '-shm');
    }

    private function cleanupRestoreWorkDirectories(): void
    {
        $backups = dirname($this->statePath);
        foreach (glob($backups . '/restore-*', GLOB_ONLYDIR) ?: [] as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                if (is_file($file) || is_link($file)) {
                    @unlink($file);
                }
            }
            @rmdir($directory);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("ディレクトリを作成できません: {$directory}");
        }
    }
}
