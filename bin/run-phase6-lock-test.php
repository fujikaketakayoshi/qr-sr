<?php

declare(strict_types=1);

use QrRally\Config\Config;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\SqliteWriteRetrier;
use QrRally\Exception\DatabaseBusyException;
use QrRally\Repository\ParticipantRepository;
use QrRally\Security\ParticipantToken;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$options = getopt('', [
    'database:',
    'output-directory:',
    'help',
    'internal-hold-lock:',
    'ready-file:',
    'release-file:',
    'released-file:',
]);

if (isset($options['internal-hold-lock'])) {
    runInternalLockHolder($options);
}

if (array_key_exists('help', $options)) {
    fwrite(STDOUT, implode("\n", [
        'Usage: php bin/run-phase6-lock-test.php --database=PATH --output-directory=PATH',
        '',
        '  --database=PATH          run-phase6-load-test.phpが保存した試験DB',
        '  --output-directory=PATH  試験DBと結果を置く同一の絶対ディレクトリ',
        '  --help                   このヘルプを表示する',
        '',
    ]));
    exit(0);
}

try {
    $config = Config::load($root . '/config/app.php');
    if ($config->isProduction() || !$config->bool('allow_development_tools')) {
        throw new RuntimeException('This command is available only in an explicitly enabled development environment.');
    }

    $databasePath = requiredOption($options, 'database');
    $outputDirectory = requiredOption($options, 'output-directory');
    [$databasePath, $outputDirectory] = validateTestPaths(
        $databasePath,
        $outputDirectory,
        $config->string('database_path'),
    );

    $normalDatabasePath = $config->string('database_path');
    $normalHashBefore = is_file($normalDatabasePath) ? hash_file('sha256', $normalDatabasePath) : null;
    $databaseHashBefore = hash_file('sha256', $databasePath);
    $database = (new ConnectionFactory())->connect($databasePath, 1);
    assertExpectedFixture($database);

    $participantId = 1;
    $spotId = 50;
    removeAcquisition($database, $participantId, $spotId);

    $successfulRetry = runLockedAcquisition(
        $root,
        $databasePath,
        $participantId,
        $spotId,
        releaseOnRetry: 2,
    );
    removeAcquisition($database, $participantId, $spotId);

    $exhaustedRetry = runLockedAcquisition(
        $root,
        $databasePath,
        $participantId,
        $spotId,
        releaseOnRetry: null,
    );

    $afterFailure = measureAcquisition($databasePath, $participantId, $spotId);
    $duplicate = measureAcquisition($databasePath, $participantId, $spotId);

    $finalCount = acquisitionRows($database, $participantId, $spotId);
    $integrity = (string) $database->query('PRAGMA integrity_check')->fetchColumn();
    $foreignKeyViolations = count($database->query('PRAGMA foreign_key_check')->fetchAll());
    $journalMode = (string) $database->query('PRAGMA journal_mode')->fetchColumn();
    unset($database);

    $normalHashAfter = is_file($normalDatabasePath) ? hash_file('sha256', $normalDatabasePath) : null;
    $report = [
        'generated_at' => gmdate('c'),
        'php_version' => PHP_VERSION,
        'database_file' => $databasePath,
        'database_hash_before' => $databaseHashBefore,
        'database_hash_after' => hash_file('sha256', $databasePath),
        'successful_retry' => $successfulRetry,
        'exhausted_retry' => $exhaustedRetry,
        'after_failure_retry' => $afterFailure,
        'duplicate_retry' => $duplicate,
        'final_target_acquisition_count' => $finalCount,
        'integrity_check' => $integrity,
        'foreign_key_violations' => $foreignKeyViolations,
        'journal_mode' => $journalMode,
        'normal_database_hash_before' => $normalHashBefore,
        'normal_database_hash_after' => $normalHashAfter,
        'normal_database_unchanged' => $normalHashBefore === $normalHashAfter,
    ];

    $name = 'lock-test-' . gmdate('Ymd-His');
    $jsonPath = $outputDirectory . '/' . $name . '.json';
    $markdownPath = $outputDirectory . '/' . $name . '.md';
    writeArtifact($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    writeArtifact($markdownPath, markdownReport($report));

    fwrite(STDOUT, "フェーズ6ロック試験が完了しました。\nJSON: {$jsonPath}\nMarkdown: {$markdownPath}\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'ロック試験に失敗しました: ' . $error->getMessage() . "\n");
    exit(1);
}

/** @param array<string,mixed> $options */
function runInternalLockHolder(array $options): never
{
    if (getenv('QR_RALLY_LOCK_TEST_CHILD') !== '1') {
        fwrite(STDERR, "Internal mode is unavailable.\n");
        exit(2);
    }

    $databasePath = requiredOption($options, 'internal-hold-lock');
    $readyFile = requiredOption($options, 'ready-file');
    $releaseFile = requiredOption($options, 'release-file');
    $releasedFile = requiredOption($options, 'released-file');
    $database = (new ConnectionFactory())->connect($databasePath, 1);
    $database->exec('BEGIN IMMEDIATE');
    $database->exec("UPDATE participants SET nickname = nickname WHERE id = 1");
    touch($readyFile);

    $deadline = microtime(true) + 10;
    while (!is_file($releaseFile) && microtime(true) < $deadline) {
        usleep(1_000);
    }
    if (!is_file($releaseFile)) {
        $database->exec('ROLLBACK');
        fwrite(STDERR, "Lock release timed out.\n");
        exit(3);
    }
    $database->exec('COMMIT');
    touch($releasedFile);
    exit(0);
}

/** @param array<string,mixed> $options */
function requiredOption(array $options, string $name): string
{
    $value = $options[$name] ?? null;
    if (!is_string($value) || $value === '') {
        throw new InvalidArgumentException("--{$name} is required.");
    }
    return $value;
}

/** @return array{string,string} */
function validateTestPaths(string $databasePath, string $outputDirectory, string $normalDatabasePath): array
{
    $realDatabase = realpath($databasePath);
    $realOutput = realpath($outputDirectory);
    $realNormal = realpath($normalDatabasePath);
    if ($realDatabase === false || !is_file($realDatabase) || $realOutput === false || !is_dir($realOutput)) {
        throw new RuntimeException('試験DBまたは出力ディレクトリが存在しません。');
    }
    if ($realNormal !== false && $realDatabase === $realNormal) {
        throw new RuntimeException('通常DBをロック試験へ指定することはできません。');
    }
    if (dirname($realDatabase) !== $realOutput || !preg_match('/^load-test-[0-9]{8}-[0-9]{6}\.sqlite$/', basename($realDatabase))) {
        throw new RuntimeException('出力ディレクトリ内の負荷試験DBだけを指定してください。');
    }
    return [$realDatabase, $realOutput];
}

function assertExpectedFixture(PDO $database): void
{
    $counts = [];
    foreach (['admins', 'spots', 'participants', 'stamp_acquisitions', 'applications'] as $table) {
        $counts[$table] = (int) $database->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
    if ($counts !== ['admins' => 1, 'spots' => 50, 'participants' => 2000, 'stamp_acquisitions' => 100000, 'applications' => 1000]) {
        throw new RuntimeException('最大想定データの試験DBではありません。');
    }
}

function removeAcquisition(PDO $database, int $participantId, int $spotId): void
{
    $statement = $database->prepare('DELETE FROM stamp_acquisitions WHERE participant_id = :participant_id AND spot_id = :spot_id');
    $statement->execute(['participant_id' => $participantId, 'spot_id' => $spotId]);
}

/** @return array<string,mixed> */
function runLockedAcquisition(
    string $root,
    string $databasePath,
    int $participantId,
    int $spotId,
    ?int $releaseOnRetry,
): array {
    $directory = sys_get_temp_dir() . '/qr-rally-lock-test-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('ロック試験用ディレクトリを作成できません。');
    }
    $readyFile = $directory . '/ready';
    $releaseFile = $directory . '/release';
    $releasedFile = $directory . '/released';
    $command = [
        PHP_BINARY,
        $root . '/bin/run-phase6-lock-test.php',
        '--internal-hold-lock=' . $databasePath,
        '--ready-file=' . $readyFile,
        '--release-file=' . $releaseFile,
        '--released-file=' . $releasedFile,
    ];
    $environment = array_merge($_ENV, ['QR_RALLY_LOCK_TEST_CHILD' => '1']);
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) {
        removeDirectory($directory);
        throw new RuntimeException('ロック保持プロセスを開始できません。');
    }
    fclose($pipes[0]);

    try {
        waitForFile($readyFile, 5_000, 'ロック開始');
        $delays = [];
        $started = hrtime(true);
        $database = (new ConnectionFactory())->connect($databasePath, 1);
        $retrier = new SqliteWriteRetrier(
            [100, 250],
            static fn (int $milliseconds): null => usleep($milliseconds * 1000),
            static function (int $attempt, int $delayMs) use (&$delays, $releaseOnRetry, $releaseFile, $releasedFile): void {
                $delays[] = $delayMs;
                if ($releaseOnRetry === $attempt) {
                    touch($releaseFile);
                    waitForFile($releasedFile, 2_000, 'ロック解除');
                }
            },
        );
        try {
            $result = (new ParticipantRepository($database, new ParticipantToken(), $retrier))
                ->acquire($participantId, $spotId, null);
            $status = 200;
            $error = null;
        } catch (DatabaseBusyException $busy) {
            $result = 'failed';
            $status = 503;
            $error = $busy::class;
        }
        $elapsed = number_format((hrtime(true) - $started) / 1_000_000, 3, '.', '');
        if (!is_file($releaseFile)) {
            touch($releaseFile);
        }
        waitForFile($releasedFile, 2_000, 'ロック解除');
        unset($database);

        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('ロック保持プロセスが失敗しました: ' . $stderr);
        }
        return [
            'result' => $result,
            'http_status_equivalent' => $status,
            'retry_delays_ms' => $delays,
            'retry_count' => count($delays),
            'elapsed_ms' => $elapsed,
            'error' => $error,
        ];
    } finally {
        if (is_resource($process)) {
            @proc_terminate($process);
        }
        foreach ([$readyFile, $releaseFile, $releasedFile] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($directory)) {
            removeDirectory($directory);
        }
    }
}

/** @return array<string,mixed> */
function measureAcquisition(string $databasePath, int $participantId, int $spotId): array
{
    $retries = 0;
    $started = hrtime(true);
    $database = (new ConnectionFactory())->connect($databasePath, 1);
    $retrier = new SqliteWriteRetrier(
        [100, 250],
        static fn (int $milliseconds): null => usleep($milliseconds * 1000),
        static function () use (&$retries): void { $retries++; },
    );
    $result = (new ParticipantRepository($database, new ParticipantToken(), $retrier))
        ->acquire($participantId, $spotId, null);
    return [
        'result' => $result,
        'http_status_equivalent' => 200,
        'retry_count' => $retries,
        'elapsed_ms' => number_format((hrtime(true) - $started) / 1_000_000, 3, '.', ''),
    ];
}

function acquisitionRows(PDO $database, int $participantId, int $spotId): int
{
    $statement = $database->prepare('SELECT COUNT(*) FROM stamp_acquisitions WHERE participant_id = :participant_id AND spot_id = :spot_id');
    $statement->execute(['participant_id' => $participantId, 'spot_id' => $spotId]);
    return (int) $statement->fetchColumn();
}

function waitForFile(string $path, int $timeoutMs, string $label): void
{
    $deadline = microtime(true) + ($timeoutMs / 1000);
    while (!is_file($path) && microtime(true) < $deadline) {
        usleep(1_000);
    }
    if (!is_file($path)) {
        throw new RuntimeException("{$label}の待機がタイムアウトしました。");
    }
}

function removeDirectory(string $directory): void
{
    if (!rmdir($directory)) {
        throw new RuntimeException('ロック試験用ディレクトリを削除できません。');
    }
}

function writeArtifact(string $path, string $contents): void
{
    if (file_exists($path) || file_put_contents($path, $contents) === false) {
        throw new RuntimeException('ロック試験結果を保存できません。');
    }
    chmod($path, 0600);
}

/** @param array<string,mixed> $report */
function markdownReport(array $report): string
{
    $successful = $report['successful_retry'];
    $exhausted = $report['exhausted_retry'];
    $afterFailure = $report['after_failure_retry'];
    $duplicate = $report['duplicate_retry'];
    return implode("\n", [
        '# フェーズ6 SQLiteロック試験結果',
        '',
        '- 実行日時: ' . $report['generated_at'],
        '- PHP: ' . $report['php_version'],
        '- 試験DB: ' . $report['database_file'],
        '- 通常DB変更なし: ' . ($report['normal_database_unchanged'] ? 'はい' : 'いいえ'),
        '- integrity_check: ' . $report['integrity_check'],
        '- 外部キー違反: ' . $report['foreign_key_violations'],
        '- journal_mode: ' . $report['journal_mode'],
        '',
        '## ロック解除後の成功',
        '',
        '- 結果: ' . $successful['result'],
        '- 再試行待機: ' . implode('ms, ', $successful['retry_delays_ms']) . 'ms',
        '- 所要時間: ' . $successful['elapsed_ms'] . 'ms',
        '',
        '## 再試行上限到達',
        '',
        '- 結果: ' . $exhausted['result'],
        '- HTTP相当: ' . $exhausted['http_status_equivalent'],
        '- 再試行待機: ' . implode('ms, ', $exhausted['retry_delays_ms']) . 'ms',
        '- 所要時間: ' . $exhausted['elapsed_ms'] . 'ms',
        '',
        '## ロック解除後の再取得',
        '',
        '- 結果: ' . $afterFailure['result'],
        '- 再試行: ' . $afterFailure['retry_count'],
        '- 重複確認: ' . $duplicate['result'],
        '- 重複時の再試行: ' . $duplicate['retry_count'],
        '- 最終対象取得履歴数: ' . $report['final_target_acquisition_count'],
        '',
    ]);
}
