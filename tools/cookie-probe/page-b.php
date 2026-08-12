<?php

declare(strict_types=1);

require __DIR__ . '/_common.php';

$mode = probeMode();
$cookieName = probeCookieName($mode);
$received = isset($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName]) ? $_COOKIE[$cookieName] : null;
$latest = probeReadState()[$mode] ?? null;
if ($received === null) {
    $result = 'Cookieなし'; $class = 'bad'; $detail = 'PHPは検証Cookieを受信していません。';
} elseif (is_array($latest) && isset($latest['id']) && hash_equals((string) $latest['id'], $received)) {
    $result = '同じCookieを確認'; $class = 'ok'; $detail = 'Aで最後に発行した検証IDと一致しました。';
} else {
    $result = '別の検証ID'; $class = 'warn'; $detail = 'Cookieは届きましたが、Aで最後に発行した検証IDとは一致しません。';
}
$accessedAt = probeNow();
$userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '取得できませんでした');
ob_start();
?><section class="panel"><h1>B：Cookie受信結果</h1><p class="status <?= $class ?>"><?= probeEscape($result) ?></p><p><?= probeEscape($detail) ?></p><table><tr><th>Cookie種別</th><td><?= $mode === 'persistent' ? '有効期限付き（7日間）' : 'セッション' ?></td></tr><tr><th>受信した検証ID</th><td><?= $received === null ? 'なし' : '<code>' . probeEscape($received) . '</code>' ?></td></tr><tr><th>Aで最後に発行したID</th><td><?= !is_array($latest) ? '記録なし' : '<code>' . probeEscape((string) ($latest['id'] ?? '')) . '</code>' ?></td></tr><tr><th>Aでの発行日時</th><td><?= probeEscape(is_array($latest) ? (string) ($latest['issued_at'] ?? '') : '記録なし') ?></td></tr><tr><th>Bへのアクセス日時</th><td><?= probeEscape($accessedAt) ?></td></tr><tr><th>User-Agent</th><td><code><?= probeEscape($userAgent) ?></code></td></tr></table><p class="nav"><a class="button secondary" href="<?= probeEscape(probeUrl('index.php', ['mode' => $mode])) ?>">手順とQRへ戻る</a><a class="button danger" href="<?= probeEscape(probeUrl('reset.php')) ?>">リセット</a></p></section><?php
probeRender('B Cookie確認', (string) ob_get_clean());
