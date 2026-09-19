#!/usr/bin/env php
<?php

declare(strict_types=1);

use QrRally\Tools\Release\ReleaseBuilder;
use QrRally\Tools\Release\ReleaseBuildException;

$root = dirname(__DIR__);
require $root . '/tools/lib/ReleaseBuildException.php';
require $root . '/tools/lib/ReleaseBuilder.php';

if (in_array('--help', $argv, true)) {
    fwrite(STDOUT, "Usage: php tools/build-release.php\n");
    fwrite(STDOUT, "許可リストから一般配布ZIPとSHA-256をdist/へ生成します。\n");
    exit(0);
}
if (count($argv) !== 1) {
    fwrite(STDERR, "引数は指定できません。--helpで使用方法を確認してください。\n");
    exit(2);
}
if (PHP_VERSION_ID < 80300) {
    fwrite(STDERR, "PHP 8.3以上で実行してください。\n");
    exit(1);
}

$composer = '';
foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
    $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer';
    if (is_file($candidate) && is_readable($candidate)) {
        $composer = $candidate;
        break;
    }
}
if ($composer === '') {
    fwrite(STDERR, "Composerが見つかりません。\n");
    exit(1);
}

try {
    $result = (new ReleaseBuilder($root, $root . '/dist', null, $composer))->build();
    fwrite(STDOUT, "配布ZIPを生成しました。\n");
    fwrite(STDOUT, "バージョン: {$result['version']}\n");
    fwrite(STDOUT, "ファイル数: {$result['files']}\n");
    fwrite(STDOUT, "ZIP: {$result['zip']}\n");
    fwrite(STDOUT, "SHA-256: {$result['sha256']}\n");
    fwrite(STDOUT, "チェックサム: {$result['checksum']}\n");
} catch (ReleaseBuildException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
