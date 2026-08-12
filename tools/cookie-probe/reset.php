<?php

declare(strict_types=1);

require __DIR__ . '/_common.php';

foreach ([PROBE_COOKIE_PERSISTENT, PROBE_COOKIE_SESSION] as $name) {
    setcookie($name, '', ['expires' => time() - 3600, 'path' => probePath(), 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
}
@unlink(PROBE_STATE_FILE);
ob_start();
?><section class="panel"><h1>検証Cookieを削除しました</h1><p class="status ok">有効期限付きCookieとセッションCookieの両方へ削除指示を送信し、サーバー側の発行記録も削除しました。</p><p>下の確認画面で両方が「Cookieなし」と表示されることを確認してください。</p><p class="nav"><a class="button" href="<?= probeEscape(probeUrl('index.php')) ?>">削除結果を確認する</a></p></section><?php
probeRender('Cookieリセット', (string) ob_get_clean());
