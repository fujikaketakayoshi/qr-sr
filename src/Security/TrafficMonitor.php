<?php

declare(strict_types=1);

namespace QrRally\Security;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

final class TrafficMonitor
{
    private PDO $database;
    private Closure $clock;

    public function __construct(
        string $path,
        private readonly string $appKey,
        private readonly int $requestLimit = 600,
        private readonly int $requestWindowSeconds = 60,
        private readonly int $spotThreshold = 10,
        private readonly int $spotWindowSeconds = 300,
        private readonly int $warningCooldownSeconds = 300,
        ?Closure $clock = null,
    ) {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('アクセス制限用一時ディレクトリを作成できません。');
        }
        @chmod($directory, 0700);
        $this->database = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->database->exec('PRAGMA journal_mode = WAL');
        $this->database->exec('PRAGMA synchronous = NORMAL');
        $this->database->exec('PRAGMA busy_timeout = 1000');
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS request_buckets ('
            . 'ip_hash TEXT NOT NULL, bucket INTEGER NOT NULL, request_count INTEGER NOT NULL, '
            . 'PRIMARY KEY (ip_hash, bucket)) STRICT; '
            . 'CREATE INDEX IF NOT EXISTS request_buckets_bucket_index ON request_buckets (bucket); '
            . 'CREATE TABLE IF NOT EXISTS spot_accesses ('
            . 'participant_id INTEGER NOT NULL, spot_id INTEGER NOT NULL, ip_hash TEXT NOT NULL, accessed_at INTEGER NOT NULL, '
            . 'PRIMARY KEY (participant_id, spot_id)) STRICT; '
            . 'CREATE INDEX IF NOT EXISTS spot_accesses_accessed_at_index ON spot_accesses (accessed_at); '
            . 'CREATE TABLE IF NOT EXISTS warning_cooldowns ('
            . 'participant_id INTEGER PRIMARY KEY, warned_at INTEGER NOT NULL) STRICT;',
        );
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function allowRequest(string $ipAddress): bool
    {
        $now = ($this->clock)();
        $oldestBucket = $now - $this->requestWindowSeconds + 1;
        $ipHash = $this->hashIp($ipAddress);

        $this->database->exec('BEGIN IMMEDIATE');
        try {
            $prune = $this->database->prepare('DELETE FROM request_buckets WHERE bucket < :oldest');
            $prune->execute(['oldest' => $oldestBucket]);
            $count = $this->database->prepare(
                'SELECT COALESCE(SUM(request_count), 0) FROM request_buckets '
                . 'WHERE ip_hash = :ip_hash AND bucket >= :oldest',
            );
            $count->execute(['ip_hash' => $ipHash, 'oldest' => $oldestBucket]);
            if ((int) $count->fetchColumn() >= $this->requestLimit) {
                $this->database->exec('COMMIT');
                return false;
            }
            $insert = $this->database->prepare(
                'INSERT INTO request_buckets (ip_hash, bucket, request_count) VALUES (:ip_hash, :bucket, 1) '
                . 'ON CONFLICT (ip_hash, bucket) DO UPDATE SET request_count = request_count + 1',
            );
            $insert->execute(['ip_hash' => $ipHash, 'bucket' => $now]);
            $this->database->exec('COMMIT');
            return true;
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    /** @return array<string,int|string>|null */
    public function recordSpotAccess(int $participantId, int $spotId, string $ipAddress): ?array
    {
        $now = ($this->clock)();
        $windowStart = $now - $this->spotWindowSeconds + 1;
        $ipHash = $this->hashIp($ipAddress);

        $this->database->exec('BEGIN IMMEDIATE');
        try {
            $this->database->prepare('DELETE FROM spot_accesses WHERE accessed_at < :start')
                ->execute(['start' => $windowStart]);
            $this->database->prepare('DELETE FROM warning_cooldowns WHERE warned_at < :expired')
                ->execute(['expired' => $now - $this->warningCooldownSeconds]);
            $this->database->prepare(
                'INSERT INTO spot_accesses (participant_id, spot_id, ip_hash, accessed_at) '
                . 'VALUES (:participant_id, :spot_id, :ip_hash, :accessed_at) '
                . 'ON CONFLICT (participant_id, spot_id) DO UPDATE SET ip_hash = excluded.ip_hash, accessed_at = excluded.accessed_at',
            )->execute([
                'participant_id' => $participantId,
                'spot_id' => $spotId,
                'ip_hash' => $ipHash,
                'accessed_at' => $now,
            ]);
            $count = $this->database->prepare(
                'SELECT COUNT(*) FROM spot_accesses WHERE participant_id = :participant_id AND accessed_at >= :start',
            );
            $count->execute(['participant_id' => $participantId, 'start' => $windowStart]);
            $distinctSpots = (int) $count->fetchColumn();
            $cooldown = $this->database->prepare('SELECT warned_at FROM warning_cooldowns WHERE participant_id = :id');
            $cooldown->execute(['id' => $participantId]);
            $lastWarning = $cooldown->fetchColumn();
            if ($distinctSpots < $this->spotThreshold || $lastWarning !== false) {
                $this->database->exec('COMMIT');
                return null;
            }
            $this->database->prepare(
                'INSERT INTO warning_cooldowns (participant_id, warned_at) VALUES (:id, :warned_at) '
                . 'ON CONFLICT (participant_id) DO UPDATE SET warned_at = excluded.warned_at',
            )->execute(['id' => $participantId, 'warned_at' => $now]);
            $this->database->exec('COMMIT');

            return [
                'detected_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
                'window_started_at' => gmdate('Y-m-d\TH:i:s\Z', $windowStart),
                'window_ended_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
                'distinct_spot_count' => $distinctSpots,
                'ip_hash' => $ipHash,
            ];
        } catch (Throwable $error) {
            $this->rollBack();
            throw $error;
        }
    }

    public function hashIp(string $ipAddress): string
    {
        return hash_hmac('sha256', $ipAddress, $this->appKey);
    }

    private function rollBack(): void
    {
        try {
            $this->database->exec('ROLLBACK');
        } catch (Throwable) {
            // Preserve the original failure if the transaction already ended.
        }
    }
}
