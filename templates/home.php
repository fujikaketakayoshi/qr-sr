<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QRデジタルスタンプラリー</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetUrl, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
    <main class="container">
        <p class="eyebrow">QR DIGITAL STAMP RALLY</p>
        <h1>QRデジタルスタンプラリー</h1>
        <p>アプリケーションの開発基盤が動作しています。</p>
        <dl class="status">
            <div><dt>環境</dt><dd><?= htmlspecialchars($environment, ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt>データベース</dt><dd>接続済み</dd></div>
            <div><dt>スキーマ</dt><dd><?= htmlspecialchars((string) $migrationCount, ENT_QUOTES, 'UTF-8') ?>件適用済み</dd></div>
        </dl>
        <p><a class="button" href="<?= htmlspecialchars($adminUrl, ENT_QUOTES, 'UTF-8') ?>">管理画面を開く</a></p>
    </main>
</body>
</html>
