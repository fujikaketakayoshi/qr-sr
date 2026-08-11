<?php

declare(strict_types=1);

use QrRally\Config\Config;
use QrRally\Database\ConnectionFactory;

require __DIR__ . '/vendor/autoload.php';

$config = Config::load(__DIR__ . '/config/app.php');
$database = (new ConnectionFactory())->connect(
    $config->string('database_path'),
    $config->int('database_busy_timeout_ms'),
);

return [
    'config' => $config,
    'database' => $database,
];
