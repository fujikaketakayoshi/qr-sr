<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars((string) ($title ?? 'セットアップ'), ENT_QUOTES, 'UTF-8') ?> | QRデジタルスタンプラリー</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetBase . 'assets/app.css', ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
<main class="install-main">
    <a class="brand" href="<?= htmlspecialchars($assetBase . 'install', ENT_QUOTES, 'UTF-8') ?>">QRデジタルスタンプラリー</a>
    <?= $content ?>
</main>
</body>
</html>
