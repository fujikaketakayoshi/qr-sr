<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | QRコードスタンプラリー</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($url('assets/app.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
<main class="auth-card">
    <a class="brand" href="<?= htmlspecialchars($url(), ENT_QUOTES, 'UTF-8') ?>">QRコードスタンプラリー</a>
    <?php require dirname(__DIR__) . '/demo-banner.php'; ?>
    <?= $content ?>
</main>
</body>
</html>
