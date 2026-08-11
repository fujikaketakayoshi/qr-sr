<?php

declare(strict_types=1);

use QrRally\Auth\PasswordPolicy;
use QrRally\Auth\RecoveryKey;
use QrRally\Repository\AdminRepository;
use QrRally\Repository\AuditLogRepository;

$root = dirname(__DIR__);
$container = require $root . '/bootstrap.php';
$config = $container['config'];

if (PHP_SAPI !== 'cli' || $config->isProduction() || !$config->bool('allow_development_tools')) {
    fwrite(STDERR, "Development administrator creation is disabled.\n");
    exit(1);
}

$admins = new AdminRepository($container['database']);
if ($admins->count() > 0) {
    fwrite(STDERR, "An administrator already exists.\n");
    exit(1);
}

$options = getopt('', ['email:', 'password::']);
$email = is_string($options['email'] ?? null) ? trim($options['email']) : '';
$password = is_string($options['password'] ?? null) ? $options['password'] : '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/create-admin.php --email=admin@example.com [--password=...]\n");
    exit(1);
}

if ($password === '') {
    fwrite(STDOUT, 'Password (12 characters or more): ');
    if (function_exists('shell_exec')) {
        shell_exec('stty -echo 2>/dev/null');
    }
    $password = trim((string) fgets(STDIN));
    if (function_exists('shell_exec')) {
        shell_exec('stty echo 2>/dev/null');
    }
    fwrite(STDOUT, "\n");
}

$passwordError = (new PasswordPolicy())->validate($password);
if ($passwordError !== null) {
    fwrite(STDERR, $passwordError . "\n");
    exit(1);
}

$recoveryKey = (new RecoveryKey())->generate();
$adminId = $admins->create(
    $email,
    password_hash($password, PASSWORD_DEFAULT),
    password_hash($recoveryKey, PASSWORD_DEFAULT),
);
(new AuditLogRepository($container['database']))
    ->record('admin.created', 'system', null, 'success', [], 'admin', $adminId);

fwrite(STDOUT, "Administrator created.\n");
fwrite(STDOUT, "Recovery key (shown once): {$recoveryKey}\n");
fwrite(STDOUT, "Store this key in a safe place. It is not saved in plain text.\n");
