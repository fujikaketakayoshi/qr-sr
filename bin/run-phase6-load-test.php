<?php

declare(strict_types=1);

use QrRally\Config\Config;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;
use QrRally\Repository\ApplicationRepository;
use QrRally\Repository\ParticipantRepository;
use QrRally\Security\ParticipantToken;
use QrRally\Support\CsvValue;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

try {
    $config = Config::load($root . '/config/app.php');
    if ($config->isProduction() || !$config->bool('allow_development_tools')) {
        throw new RuntimeException('フェーズ6負荷試験は開発環境でのみ実行できます。');
    }

    $normalDatabasePath = $config->string('database_path');
    $normalHashBefore = is_file($normalDatabasePath) ? hash_file('sha256', $normalDatabasePath) : null;
    $testDirectory = sys_get_temp_dir() . '/qr-rally-phase6-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
    if (!mkdir($testDirectory, 0700, true)) {
        throw new RuntimeException('負荷試験用一時ディレクトリを作成できません。');
    }
    $databasePath = $testDirectory . '/load.sqlite';
    $database = (new ConnectionFactory())->connect($databasePath, 5000);
    (new Migrator($database, $root . '/database/migrations'))->migrate();

    $measurements = [];
    $measure = static function (string $name, Closure $operation) use (&$measurements): mixed {
        $started = hrtime(true);
        $result = $operation();
        $measurements[$name] = round((hrtime(true) - $started) / 1_000_000, 3);
        return $result;
    };

    $measure('seed_ms', static function () use ($database): void {
        $database->beginTransaction();
        try {
            $database->exec(
                "INSERT INTO events (id,name,starts_at,ends_at,required_stamp_count,application_enabled,created_at,updated_at) "
                . "VALUES (1,'フェーズ6負荷試験','2026-01-01T00:00:00Z','2030-01-01T00:00:00Z',20,1,'now','now')",
            );
            $spot = $database->prepare(
                "INSERT INTO spots (public_token_hash,name,description,display_order,is_active,created_at,updated_at) "
                . "VALUES (:hash,:name,'',:display_order,1,'now','now')",
            );
            for ($spotNumber = 1; $spotNumber <= 20; $spotNumber++) {
                $spot->execute([
                    'hash' => hash('sha256', 'phase6-spot-' . $spotNumber),
                    'name' => '負荷試験スポット' . $spotNumber,
                    'display_order' => $spotNumber,
                ]);
            }
            $participant = $database->prepare(
                "INSERT INTO participants (token_hash,nickname,first_seen_at,last_seen_at,completed_at,created_at,updated_at) "
                . "VALUES (:hash,:nickname,'now','now','now','now','now')",
            );
            $acquisition = $database->prepare(
                "INSERT INTO stamp_acquisitions (participant_id,spot_id,acquired_at,ip_hash) VALUES (:participant_id,:spot_id,'now',:ip_hash)",
            );
            $application = $database->prepare(
                "INSERT INTO applications (participant_id,application_number,name,email,address,phone,privacy_accepted_at,submitted_at,updated_at) "
                . "VALUES (:participant_id,:number,:name,:email,:address,:phone,'now','now','now')",
            );
            for ($participantNumber = 1; $participantNumber <= 2_000; $participantNumber++) {
                $participant->execute([
                    'hash' => hash('sha256', 'phase6-participant-' . $participantNumber),
                    'nickname' => '参加者' . $participantNumber,
                ]);
                for ($spotNumber = 1; $spotNumber <= 20; $spotNumber++) {
                    $acquisition->execute([
                        'participant_id' => $participantNumber,
                        'spot_id' => $spotNumber,
                        'ip_hash' => hash_hmac('sha256', '192.0.2.' . ($participantNumber % 255), str_repeat('a', 64)),
                    ]);
                }
                if ($participantNumber % 2 === 0) {
                    $application->execute([
                        'participant_id' => $participantNumber,
                        'number' => sprintf('LOAD-%06d', $participantNumber),
                        'name' => '応募者' . $participantNumber,
                        'email' => "user{$participantNumber}@example.test",
                        'address' => 'テスト住所',
                        'phone' => '000-0000-0000',
                    ]);
                }
            }
            $database->exec('UPDATE application_fields SET is_enabled=1');
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }
    });

    $participants = new ParticipantRepository($database, new ParticipantToken());
    $applications = new ApplicationRepository($database);
    $measure('stamp_board_ms', static fn (): array => $participants->stampBoard(1));
    $summary = $measure('admin_summary_ms', static fn (): array => $applications->summary());
    $measure('spot_summary_ms', static fn (): array => $applications->spotSummary());
    $allRows = $measure('all_participants_query_ms', static fn (): array => $applications->exportRows());
    $applicantRows = $measure('applicants_query_ms', static fn (): array => $applications->exportApplicationRows());
    $measure('all_participants_csv_ms', static fn (): int => csvBytes($allRows));
    $measure('applicants_csv_ms', static fn (): int => csvBytes($applicantRows));

    $database->exec('DELETE FROM stamp_acquisitions WHERE participant_id=1 AND spot_id=20');
    $measure('stamp_acquire_ms', static fn (): string => $participants->acquire(1, 20, null));
    $database->exec('DELETE FROM stamp_acquisitions WHERE participant_id=1 AND spot_id=20');
    unset($database, $participants, $applications);
    $concurrency = runConcurrentAcquisition($root, $databasePath, $testDirectory, 1, 20, 8);
    $database = (new ConnectionFactory())->connect($databasePath, 5000);
    $finalAcquisitions = (int) $database->query('SELECT COUNT(*) FROM stamp_acquisitions')->fetchColumn();
    $integrity = (string) $database->query('PRAGMA integrity_check')->fetchColumn();
    $database->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    clearstatcache(true, $databasePath);
    $databaseSize = filesize($databasePath);
    unset($database);

    $normalHashAfter = is_file($normalDatabasePath) ? hash_file('sha256', $normalDatabasePath) : null;
    $report = [
        'generated_at' => gmdate('c'),
        'php_version' => PHP_VERSION,
        'conditions' => ['spots' => 20, 'participants' => 2_000, 'acquisitions' => 40_000, 'applications' => 1_000, 'concurrent_workers' => 8],
        'measurements_ms' => $measurements,
        'summary' => $summary,
        'all_participant_rows' => count($allRows),
        'applicant_rows' => count($applicantRows),
        'database_bytes' => $databaseSize,
        'concurrency' => $concurrency,
        'final_acquisition_count' => $finalAcquisitions,
        'integrity_check' => $integrity,
        'normal_database_hash_before' => $normalHashBefore,
        'normal_database_hash_after' => $normalHashAfter,
        'normal_database_unchanged' => $normalHashBefore === $normalHashAfter,
    ];

    $outputDirectory = $root . '/output/phase6';
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0755, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException('測定結果の保存先を作成できません。');
    }
    $name = 'load-test-' . gmdate('Ymd-His');
    $jsonPath = $outputDirectory . '/' . $name . '.json';
    $markdownPath = $outputDirectory . '/' . $name . '.md';
    file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    file_put_contents($markdownPath, markdownReport($report));

    cleanupTemporaryDirectory($testDirectory);
    fwrite(STDOUT, "フェーズ6負荷試験が完了しました。\nJSON: {$jsonPath}\nMarkdown: {$markdownPath}\n");
} catch (Throwable $error) {
    fwrite(STDERR, '負荷試験に失敗しました: ' . $error->getMessage() . "\n");
    exit(1);
}

/** @param list<array<string,mixed>> $rows */
function csvBytes(array $rows): int
{
    $stream = fopen('php://temp', 'w+');
    if ($stream === false) {
        throw new RuntimeException('CSV測定用ストリームを作成できません。');
    }
    if ($rows !== []) {
        fwrite($stream, "\xEF\xBB\xBF");
        $safe = new CsvValue();
        fputcsv($stream, array_keys($rows[0]), escape: '');
        foreach ($rows as $row) {
            fputcsv($stream, array_map(static fn (mixed $value): string => $safe->safe((string) ($value ?? '')), $row), escape: '');
        }
    }
    $bytes = ftell($stream);
    fclose($stream);
    return $bytes === false ? 0 : $bytes;
}

/** @return array<string,int> */
function runConcurrentAcquisition(string $root, string $databasePath, string $directory, int $participantId, int $spotId, int $workers): array
{
    $barrier = $directory . '/start-workers';
    $processes = [];
    for ($worker = 0; $worker < $workers; $worker++) {
        $command = [PHP_BINARY, $root . '/bin/phase6-concurrent-worker.php', $databasePath, $barrier, (string) $participantId, (string) $spotId];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('同時取得ワーカーを開始できません。');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes[1], $pipes[2]];
    }
    touch($barrier);
    $counts = ['acquired' => 0, 'duplicate' => 0, 'failed' => 0, 'lock_retries' => 0];
    foreach ($processes as [$process, $stdout, $stderr]) {
        $result = json_decode((string) stream_get_contents($stdout), true);
        $error = (string) stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        $exitCode = proc_close($process);
        if (!is_array($result)) {
            throw new RuntimeException('同時取得ワーカーの結果を読み取れません: ' . $error);
        }
        $kind = (string) ($result['result'] ?? 'failed');
        $counts[array_key_exists($kind, $counts) ? $kind : 'failed']++;
        $counts['lock_retries'] += (int) ($result['lock_retries'] ?? 0);
        if ($exitCode !== 0 && $kind !== 'failed') {
            $counts['failed']++;
        }
    }
    return $counts;
}

/** @param array<string,mixed> $report */
function markdownReport(array $report): string
{
    $lines = [
        '# フェーズ6 負荷試験結果', '',
        '- 実行日時: ' . $report['generated_at'],
        '- PHP: ' . $report['php_version'],
        '- スポット: 20件', '- 参加者: 2,000人', '- 取得履歴: 40,000件', '- 応募者: 1,000人',
        '- DBサイズ: ' . number_format((int) $report['database_bytes']) . ' bytes',
        '- integrity_check: ' . $report['integrity_check'],
        '- 通常DB変更なし: ' . ($report['normal_database_unchanged'] ? 'はい' : 'いいえ'), '',
        '## 応答時間', '', '| 項目 | ms |', '|---|---:|',
    ];
    foreach ($report['measurements_ms'] as $name => $milliseconds) {
        $lines[] = "| {$name} | {$milliseconds} |";
    }
    $concurrency = $report['concurrency'];
    $lines = array_merge($lines, ['', '## 同時取得', '',
        '- 成功: ' . $concurrency['acquired'],
        '- 取得済み: ' . $concurrency['duplicate'],
        '- 失敗: ' . $concurrency['failed'],
        '- SQLiteロック再試行: ' . $concurrency['lock_retries'],
        '- 最終取得履歴数: ' . $report['final_acquisition_count'], '',
    ]);
    return implode("\n", $lines);
}

function cleanupTemporaryDirectory(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($directory);
}
