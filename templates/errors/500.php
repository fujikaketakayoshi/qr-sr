<!doctype html>
<html lang="ja">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>エラー</title></head>
<body>
<main>
    <h1>ページを表示できませんでした</h1>
    <p>時間をおいて、もう一度お試しください。</p>
    <p>お問い合わせ番号: <?= htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($debugMessage !== null): ?>
        <pre><?= htmlspecialchars($debugMessage, ENT_QUOTES, 'UTF-8') ?></pre>
    <?php endif; ?>
</main>
</body>
</html>
