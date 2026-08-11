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

$configurationPath = $root . '/config/app.php';
if (!is_file($configurationPath)) {
    if (!copy($root . '/config/app.example.php', $configurationPath)) {
        fwrite(STDERR, "Could not create config/app.php.\n");
        exit(1);
    }
    fwrite(STDOUT, "Created config/app.php. Set APP_BASE_URL for this machine.\n");
}

$config = Config::load($configurationPath);
if ($config->isProduction() || !$config->bool('allow_development_tools')) {
    fwrite(STDERR, "Local setup is disabled outside development.\n");
    exit(1);
}

$database = (new ConnectionFactory())->connect(
    $config->string('database_path'),
    $config->int('database_busy_timeout_ms'),
);
$applied = (new Migrator($database, $root . '/database/migrations'))->migrate();

fwrite(STDOUT, $applied === []
    ? "Database is already up to date.\n"
    : 'Applied migrations: ' . implode(', ', $applied) . "\n");
fwrite(STDOUT, 'Ready: ' . $config->string('base_url') . "\n");
