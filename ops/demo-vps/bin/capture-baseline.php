<?php

declare(strict_types=1);

use QrRally\Config\Config;
use QrRally\Ops\Demo\DemoDatabaseResetter;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/lib/DemoDatabaseResetter.php';
$config = Config::load($root . '/config/app.php');
if (!$config->bool('demo_mode')) {
    fwrite(STDERR, "demo_modeが有効ではありません。\n");
    exit(1);
}
$private = dirname($config->string('database_path'));
$resetter = new DemoDatabaseResetter(
    $config->string('database_path'),
    $private . '/demo/baseline.sqlite',
    $private . '/backups/restore-state.json',
    $private . '/runtime/demo-reset.lock',
    $private . '/logs/demo-reset.log',
    $config->string('timezone'),
);
try {
    $resetter->captureBaseline();
    fwrite(STDOUT, "現在のDBをデモ用基準DBとして保存しました。\n");
} catch (Throwable $error) {
    fwrite(STDERR, "基準DBの保存に失敗しました。運用ログを確認してください。\n");
    exit(1);
}
