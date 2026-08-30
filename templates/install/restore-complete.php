<h1>新しい環境への復元が完了しました</h1>
<p class="flash flash-success">バックアップ内の管理者アカウントとイベントデータを復元しました。</p>
<?php if ($sourceBaseUrl !== $baseUrl): ?>
    <p class="warning-text"><strong>ベースURLが変わりました。</strong> イベント入口とすべてのスポットのQRコードはURLが変わっています。新しい管理画面から再印刷し、印刷・掲示・配布済みのものをすべて交換してください。</p>
<?php endif; ?>
<a class="button" href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>">管理画面へ進む</a>
