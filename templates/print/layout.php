<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | QRスタンプラリー</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($url('assets/print.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
<div class="print-toolbar">
    <button type="button" onclick="window.print()">印刷する</button>
    <a href="<?= htmlspecialchars($url('admin/spots'), ENT_QUOTES, 'UTF-8') ?>">スポット管理へ戻る</a>
</div>
<main><?= $content ?></main>
</body>
</html>
