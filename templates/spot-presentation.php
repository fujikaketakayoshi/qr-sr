<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($spot['name'], ENT_QUOTES, 'UTF-8') ?> | 提示用QRコード</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($url('assets/app.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
<main class="qr-presentation">
    <?php require __DIR__ . '/demo-banner.php'; ?>
    <section class="panel qr-panel">
        <p class="presentation-event"><?= htmlspecialchars($event['name'], ENT_QUOTES, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars($spot['name'], ENT_QUOTES, 'UTF-8') ?></h1>
        <img class="qr-image" src="<?= htmlspecialchars($qrDataUri, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($spot['name'], ENT_QUOTES, 'UTF-8') ?>のスタンプ取得用QRコード">
        <p>スマートフォンの標準カメラで読み取ってください。</p>
        <a class="button secondary" href="<?= htmlspecialchars($printUrl, ENT_QUOTES, 'UTF-8') ?>">印刷画面を開く</a>
    </section>
</main>
</body>
</html>
