<article class="print-page spot-page">
    <p class="event-name"><?= htmlspecialchars($event['name'], ENT_QUOTES, 'UTF-8') ?></p>
    <p class="spot-number">SPOT <?= sprintf('%02d', (int) $spot['display_order']) ?></p>
    <h1><?= htmlspecialchars($spot['name'], ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($spot['description'] !== ''): ?><p class="spot-description"><?= nl2br(htmlspecialchars($spot['description'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
    <img class="qr-code spot-qr" src="<?= htmlspecialchars($spot['qr_data_uri'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($spot['name'], ENT_QUOTES, 'UTF-8') ?>のQRコード">
    <p class="scan-lead">標準カメラで読み取ってスタンプを獲得！</p>
    <aside class="browser-notice compact">
        <p>普段使用する通常ブラウザで開き、イベント中は毎回同じブラウザをご利用ください。</p>
        <p>iPhoneは「コードスキャナー」ではなく、標準カメラからSafariで開いてください。</p>
    </aside>
</article>
