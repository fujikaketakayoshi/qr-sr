<?php

declare(strict_types=1);

use QrRally\Database\ConnectionFactory;
use QrRally\Database\SqliteWriteRetrier;
use QrRally\Repository\ParticipantRepository;
use QrRally\Security\ParticipantToken;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

[$script, $databasePath, $barrierPath, $participantId, $spotId] = $argv + [null, null, null, null, null];
$temporaryRoot = realpath(sys_get_temp_dir());
$databaseDirectory = is_string($databasePath) ? realpath(dirname($databasePath)) : false;
if ($temporaryRoot === false || $databaseDirectory === false || !str_starts_with($databaseDirectory, $temporaryRoot . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Worker accepts only a temporary test database.\n");
    exit(2);
}

$deadline = microtime(true) + 10;
while (!is_file((string) $barrierPath) && microtime(true) < $deadline) {
    usleep(10_000);
}
if (!is_file((string) $barrierPath)) {
    fwrite(STDERR, "Worker barrier timed out.\n");
    exit(3);
}

$retryCount = 0;
try {
    $database = (new ConnectionFactory())->connect((string) $databasePath, 1);
    $retrier = new SqliteWriteRetrier(
        [100, 250],
        static fn (int $milliseconds): int => usleep($milliseconds * 1000),
        static function () use (&$retryCount): void { $retryCount++; },
    );
    $result = (new ParticipantRepository($database, new ParticipantToken(), $retrier))
        ->acquire((int) $participantId, (int) $spotId, null);
    fwrite(STDOUT, json_encode(['result' => $result, 'lock_retries' => $retryCount], JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    fwrite(STDOUT, json_encode([
        'result' => 'failed',
        'lock_retries' => $retryCount,
        'error' => $error::class,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
