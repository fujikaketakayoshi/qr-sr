<div class="page-heading"><div><p class="eyebrow">QR CODE CHECK</p><h1><?= htmlspecialchars($spot['name'], ENT_QUOTES, 'UTF-8') ?></h1></div><a href="<?= htmlspecialchars($url('admin/spots'), ENT_QUOTES, 'UTF-8') ?>">一覧へ戻る</a></div>
<div class="qr-layout">
    <section class="panel qr-panel">
        <img class="qr-image" src="<?= htmlspecialchars($qrDataUri, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($spot['name'], ENT_QUOTES, 'UTF-8') ?>のQRコード">
        <p class="qr-url"><?= htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8') ?></p>
        <div class="qr-actions">
            <a class="button" href="<?= htmlspecialchars($url("admin/spots/{$spot['management_token']}/qr.svg"), ENT_QUOTES, 'UTF-8') ?>">SVGをダウンロード</a>
            <a class="button secondary" href="<?= htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">遷移先を開く</a>
        </div>
    </section>
    <aside class="panel notice">
        <a class="button secondary" href="<?= htmlspecialchars($presentationUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">提示用QRコードを表示</a>
        <p>ログイン不要。開いた提示ページのURLをスポット担当者へ共有できます。</p>
        <h2>QRコードの再発行</h2>
        <?php if ($demoMode ?? false): ?>
            <p class="demo-disabled">公開デモでは、QRコードの再発行は利用できません。</p>
        <?php else: ?>
            <p class="warning-text">再発行すると古いQRコードは使えなくなります。印刷・掲示・配布済みのQRコードは、すべて新しいものへ交換してください。担当者へ共有した提示用URLも無効になります。新しいURLを共有し直してください。</p>
            <form method="post" action="<?= htmlspecialchars($url("admin/spots/{$spot['management_token']}/reissue"), ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm('古いQRコードは使えなくなります。印刷・掲示・配布済みのQRコードをすべて交換する必要があります。担当者へ共有した提示用URLも無効になります。新しいURLを共有し直してください。再発行しますか？');">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <button class="danger" type="submit">QRコードを再発行</button>
            </form>
        <?php endif; ?>
    </aside>
</div>
