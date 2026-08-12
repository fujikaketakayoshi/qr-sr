<?php

declare(strict_types=1);

require __DIR__ . '/_common.php';

$mode = probeMode();
$persistent = isset($_COOKIE[PROBE_COOKIE_PERSISTENT]) ? (string) $_COOKIE[PROBE_COOKIE_PERSISTENT] : null;
$session = isset($_COOKIE[PROBE_COOKIE_SESSION]) ? (string) $_COOKIE[PROBE_COOKIE_SESSION] : null;
$aUrl = probeUrl('page-a.php', ['mode' => $mode]);
$bUrl = probeUrl('page-b.php', ['mode' => $mode]);
ob_start();
?><section class="panel"><h1>iPhone QR Cookie保持検証</h1><p>開発専用ツールです。Aで発行したCookieを、別途読み取ったBへPHPが受信できるか確認します。</p><div class="modes"><a class="button <?= $mode === 'persistent' ? '' : 'secondary' ?>" href="?mode=persistent">有効期限付きCookie</a><a class="button <?= $mode === 'session' ? '' : 'secondary' ?>" href="?mode=session">セッションCookie</a></div><p>現在の検証種別：<strong><?= $mode === 'persistent' ? '有効期限付き（7日間）' : 'セッション' ?></strong></p></section>
<section class="panel"><h2>現在PHPが認識しているCookie</h2><table><tr><th>有効期限付き</th><td><?= $persistent === null ? 'Cookieなし' : '<code>' . probeEscape($persistent) . '</code>' ?></td></tr><tr><th>セッション</th><td><?= $session === null ? 'Cookieなし' : '<code>' . probeEscape($session) . '</code>' ?></td></tr></table><p class="nav"><a class="button" href="<?= probeEscape($aUrl) ?>">Aを開く</a><a class="button" href="<?= probeEscape($bUrl) ?>">Bを開く</a><a class="button danger" href="<?= probeEscape(probeUrl('reset.php')) ?>">リセット</a></p></section>
<section class="panel"><h2>実機検証手順</h2><ol><li>コードスキャナーでAのQRを読み取る</li><li>AでCookieが発行されたことを確認する</li><li>コードスキャナーの画面を閉じる</li><li>コードスキャナーでBのQRを別途読み取る</li><li>Cookieが保持されているか確認する</li><li>画面ロック後にも試す</li><li>別アプリを使用した後にも試す</li><li>数分〜数時間空けて試す</li><li>iPhone標準カメラ＋Safariでも同じ手順を試す</li><li>Safariのプライベートブラウズでも比較する</li></ol><p>条件を変える前にリセットし、Aから新しい検証IDを発行してください。通常リンクでのA→B移動と、QRを別々に読む試験は区別して記録してください。</p></section>
<section class="panel"><h2><?= $mode === 'persistent' ? '有効期限付き' : 'セッション' ?>Cookie用QR</h2><div class="qr-grid"><div class="qr"><h3>A：Cookie発行</h3><img src="<?= probeEscape(probeQrDataUri($aUrl)) ?>" alt="AページのQRコード"><p><code><?= probeEscape($aUrl) ?></code></p></div><div class="qr"><h3>B：Cookie確認</h3><img src="<?= probeEscape(probeQrDataUri($bUrl)) ?>" alt="BページのQRコード"><p><code><?= probeEscape($bUrl) ?></code></p></div></div></section><?php
probeRender('Cookie保持検証', (string) ob_get_clean());
