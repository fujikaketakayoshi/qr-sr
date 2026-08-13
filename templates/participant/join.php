<section class="panel">
    <p class="eyebrow">WELCOME</p>
    <h1><?= htmlspecialchars($event['name'] ?? 'QRデジタルスタンプラリー', ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($event): ?>
        <p><?= nl2br(htmlspecialchars($event['description'], ENT_QUOTES, 'UTF-8')) ?></p>
    <?php else: ?>
        <p class="error">イベントはまだ設定されていません。</p>
    <?php endif; ?>

    <?php if ($event): ?>
        <aside class="scanner-warning" role="alert" aria-labelledby="scanner-warning-title">
            <h2 id="scanner-warning-title">iPhoneをご利用の方へ</h2>
            <p><strong>コントロールセンターの「コードスキャナー」で開いている場合は、この画面を閉じてください。</strong></p>
            <p>標準の「カメラ」アプリでQRコードを読み直し、Safariから参加してください。コードスキャナーでは、画面を閉じると参加履歴やスタンプが引き継がれません。</p>
        </aside>

        <h2>はじめて参加する</h2>
        <?php require dirname(__DIR__) . '/form-error-summary.php'; ?>
        <form method="post" action="<?= htmlspecialchars($url('join'), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="spot_token" value="<?= htmlspecialchars($spotToken, ENT_QUOTES, 'UTF-8') ?>">
            <label>
                ニックネーム
                <input name="nickname" maxlength="50" required value="<?= htmlspecialchars($nickname, ENT_QUOTES, 'UTF-8') ?>">
                <?php if (isset($errors['nickname'])): ?>
                    <small class="field-error"><?= htmlspecialchars($errors['nickname'], ENT_QUOTES, 'UTF-8') ?></small>
                <?php endif; ?>
            </label>
            <div class="notice-text">
                <strong>参加前にご確認ください</strong>
                <p><?= nl2br(htmlspecialchars($event['notice_text'] ?: 'このブラウザのCookieに参加情報を保存します。', ENT_QUOTES, 'UTF-8')) ?></p>
                <p>Androidは標準カメラから普段使用するブラウザで開き、イベント中は毎回同じブラウザをご利用ください。</p>
                <p><a href="<?= htmlspecialchars($url('notices'), ENT_QUOTES, 'UTF-8') ?>">Cookie・機種変更などの諸注意</a></p>
            </div>
            <label class="checkbox">
                <input type="checkbox" name="notice_accepted" value="1" required>
                注意事項を確認し、同意します
            </label>
            <?php if (isset($errors['notice_accepted'])): ?>
                <p class="field-error"><?= htmlspecialchars($errors['notice_accepted'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <?php if (isset($errors['spot_token'])): ?>
                <p class="error"><?= htmlspecialchars($errors['spot_token'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <button type="submit">参加する</button>
        </form>
    <?php endif; ?>
</section>
