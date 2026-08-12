<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use QrRally\Support\QrCodeGenerator;

const PROBE_COOKIE_PERSISTENT = 'qr_cookie_probe_persistent';
const PROBE_COOKIE_SESSION = 'qr_cookie_probe_session';
const PROBE_STATE_FILE = __DIR__ . '/../../storage/cookie-probe-state.json';

function probeMode(): string
{
    return ($_GET['mode'] ?? '') === 'session' ? 'session' : 'persistent';
}

function probeCookieName(string $mode): string
{
    return $mode === 'session' ? PROBE_COOKIE_SESSION : PROBE_COOKIE_PERSISTENT;
}

function probePath(): string
{
    $directory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/tools/cookie-probe/index.php')));
    return '/' . trim($directory, '/') . '/';
}

function probeBaseUrl(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (preg_match('/^[A-Za-z0-9.:[\]-]+$/D', $host) !== 1) {
        $host = 'localhost';
    }
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . $host . probePath();
}

function probeUrl(string $file, array $query = []): string
{
    return probeBaseUrl() . $file . ($query === [] ? '' : '?' . http_build_query($query));
}

function probeEscape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function probeNow(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s T');
}

/** @return array<string, array{id: string, issued_at: string}> */
function probeReadState(): array
{
    $json = @file_get_contents(PROBE_STATE_FILE);
    if (!is_string($json)) {
        return [];
    }
    $state = json_decode($json, true);
    return is_array($state) ? $state : [];
}

function probeRecordIssue(string $mode, string $id, string $issuedAt): void
{
    $handle = fopen(PROBE_STATE_FILE, 'c+');
    if ($handle === false) {
        throw new RuntimeException('検証記録ファイルを開けません。storageの書込権限を確認してください。');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('検証記録をロックできません。');
        }
        $json = stream_get_contents($handle);
        $state = is_string($json) ? json_decode($json, true) : [];
        if (!is_array($state)) {
            $state = [];
        }
        $state[$mode] = ['id' => $id, 'issued_at' => $issuedAt];
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function probeQrDataUri(string $url): string
{
    return 'data:image/svg+xml;base64,' . base64_encode((new QrCodeGenerator())->svg($url));
}

/** @param array<string, mixed> $data */
function probeRender(string $title, string $content, array $data = []): never
{
    extract($data, EXTR_SKIP);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');
    ?><!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= probeEscape($title) ?></title><style>
    :root{font-family:-apple-system,BlinkMacSystemFont,"Hiragino Sans","Yu Gothic",sans-serif;color:#18221f;background:#eef3f1}body{margin:0}.wrap{width:min(calc(100% - 2rem),60rem);margin:2rem auto}.panel{margin:1rem 0;padding:1.3rem;background:#fff;border-radius:.8rem;box-shadow:0 .2rem 1rem #1231}h1,h2{line-height:1.3}.nav,.modes{display:flex;flex-wrap:wrap;gap:.6rem}.button{display:inline-block;padding:.7rem 1rem;border-radius:.5rem;color:#fff;background:#087f5b;text-decoration:none;font-weight:700}.secondary{color:#075c42;background:#dcefe8}.danger{background:#b42318}.status{padding:1rem;border-radius:.5rem;font-weight:800}.ok{color:#075c42;background:#dff6ed}.warn{color:#8a4b00;background:#fff3cc}.bad{color:#991b1b;background:#fee2e2}code{overflow-wrap:anywhere}.qr-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1rem}.qr{text-align:center}.qr img{width:min(100%,20rem)}table{width:100%;border-collapse:collapse}th,td{padding:.65rem;border-bottom:1px solid #d9e2df;text-align:left;vertical-align:top}ol,ul{line-height:1.75}@media(max-width:42rem){.qr-grid{grid-template-columns:1fr}}
    </style></head><body><main class="wrap"><?= $content ?></main></body></html><?php
    exit;
}
