<?php

declare(strict_types=1);

use QrRally\Config\Config;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only run from the command line.\n");
    exit(1);
}

$config = Config::load($root . '/config/app.php');
if ($config->isProduction() || !$config->bool('allow_development_tools')) {
    fwrite(STDERR, "Local reset is disabled outside development.\n");
    exit(1);
}

$databasePath = $config->string('database_path');
$resolvedStorage = realpath($root . '/storage');
$databaseDirectory = realpath(dirname($databasePath));
if ($resolvedStorage === false || $databaseDirectory !== $resolvedStorage) {
    fwrite(STDERR, "Refusing to reset a database outside the local storage directory.\n");
    exit(1);
}

foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
    if (is_file($path) && !unlink($path)) {
        fwrite(STDERR, "Could not remove local database file.\n");
        exit(1);
    }
}

$database = (new ConnectionFactory())->connect(
    $databasePath,
    $config->int('database_busy_timeout_ms'),
);
$applied = (new Migrator($database, $root . '/database/migrations'))->migrate();

fwrite(STDOUT, 'Local database reset. Applied migrations: ' . implode(', ', $applied) . "\n");
