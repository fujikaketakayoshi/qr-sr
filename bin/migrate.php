<?php

declare(strict_types=1);

use QrRally\Database\Migrator;

$root = dirname(__DIR__);
$container = require $root . '/bootstrap.php';
$applied = (new Migrator($container['database'], $root . '/database/migrations'))->migrate();

fwrite(STDOUT, $applied === []
    ? "Database is already up to date.\n"
    : 'Applied migrations: ' . implode(', ', $applied) . "\n");
