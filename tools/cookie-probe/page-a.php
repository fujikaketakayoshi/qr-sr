<?php

declare(strict_types=1);

require __DIR__ . '/_common.php';

$mode = probeMode();
$id = bin2hex(random_bytes(16));
$issuedAt = probeNow();
$options = ['expires' => $mode === 'persistent' ? time() + 604800 : 0, 'path' => probePath(), 'secure' => false, 'httponly' => true, 'samesite' => 'Lax'];
if (!setcookie(probeCookieName($mode), $id, $options)) {
    throw new RuntimeException('Cookieを発行できませんでした。');
}
probeRecordIssue($mode, $id, $issuedAt);
ob_start();
?><section class="panel"><h1>A：Cookieを発行しました</h1><p class="status ok">レスポンスで検証Cookieを発行しました。</p><table><tr><th>検証ID</th><td><code><?= probeEscape($id) ?></code></td></tr><tr><th>Cookie種別</th><td><?= $mode === 'persistent' ? '有効期限付き（7日間）' : 'セッション' ?></td></tr><tr><th>属性</th><td>HttpOnly / SameSite=Lax / Secure=false / Path=<?= probeEscape(probePath()) ?></td></tr><tr><th>発行日時</th><td><?= probeEscape($issuedAt) ?></td></tr></table><p>この画面を閉じ、BのQRコードを別途読み取ってください。</p><p class="nav"><a class="button" href="<?= probeEscape(probeUrl('page-b.php', ['mode' => $mode])) ?>">通常リンクでBへ移動</a><a class="button secondary" href="<?= probeEscape(probeUrl('index.php', ['mode' => $mode])) ?>">手順とQRへ戻る</a></p></section><?php
probeRender('A Cookie発行', (string) ob_get_clean());
