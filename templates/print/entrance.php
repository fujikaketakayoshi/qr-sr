<article class="print-page entrance-page">
    <p class="kicker">QR DIGITAL STAMP RALLY</p>
    <h1><?= htmlspecialchars($event['name'], ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($event['description'] !== ''): ?><p class="event-description"><?= nl2br(htmlspecialchars($event['description'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
    <p class="event-period"><strong>開催期間</strong> <?= htmlspecialchars($startsAt, ENT_QUOTES, 'UTF-8') ?> ～ <?= htmlspecialchars($endsAt, ENT_QUOTES, 'UTF-8') ?></p>
    <div class="entrance-grid">
        <img class="qr-code" src="<?= htmlspecialchars($qrDataUri, ENT_QUOTES, 'UTF-8') ?>" alt="イベント入口QRコード">
        <section>
            <h2>参加方法</h2>
            <ol>
                <li>標準カメラでQRコードを読み取る</li>
                <li>ニックネームを登録する</li>
                <li>各スポットでスタンプを集める</li>
                <li><?= (int) $event['required_stamp_count'] ?>個集めて達成<?= $event['application_enabled'] ? '・応募' : '' ?></li>
            </ol>
        </section>
    </div>
    <aside class="browser-notice">
        <strong>読み取り方法にご注意ください</strong>
        <p>標準カメラから、普段使用する通常ブラウザで開いてください。イベント中は毎回同じブラウザをご利用ください。</p>
        <p>iPhoneはコントロールセンターの「コードスキャナー」を使わず、標準の「カメラ」アプリからSafariで開いてください。アプリ内ブラウザやプライベートブラウズでは、参加履歴が失われる場合があります。</p>
        <p>インターネット通信にかかる費用は参加者の負担となります。</p>
    </aside>
    <?php if ($event['notice_text'] !== ''): ?><section class="full-notice"><h2>参加前の注意事項</h2><p><?= nl2br(htmlspecialchars($event['notice_text'], ENT_QUOTES, 'UTF-8')) ?></p></section><?php endif; ?>
    <p class="print-url"><?= htmlspecialchars($entryUrl, ENT_QUOTES, 'UTF-8') ?></p>
</article>
