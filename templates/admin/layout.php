<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars((string) ($title ?? '管理画面'), ENT_QUOTES, 'UTF-8') ?> | 管理画面</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($url('assets/app.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
<header class="admin-header">
    <a class="brand" href="<?= htmlspecialchars($url('admin/'), ENT_QUOTES, 'UTF-8') ?>">QRスタンプラリー管理</a>
    <nav>
        <a href="<?= htmlspecialchars($url('admin/'), ENT_QUOTES, 'UTF-8') ?>">概要</a>
        <a href="<?= htmlspecialchars($url('admin/event'), ENT_QUOTES, 'UTF-8') ?>">イベント設定</a>
        <a href="<?= htmlspecialchars($url('admin/spots'), ENT_QUOTES, 'UTF-8') ?>">スポット</a>
        <a href="<?= htmlspecialchars($url('admin/applications'), ENT_QUOTES, 'UTF-8') ?>">参加・応募</a>
        <a href="<?= htmlspecialchars($url('admin/applications/settings'), ENT_QUOTES, 'UTF-8') ?>">応募設定</a>
        <a href="<?= htmlspecialchars($url('admin/logs'), ENT_QUOTES, 'UTF-8') ?>">操作ログ</a>
        <form method="post" action="<?= htmlspecialchars($url('admin/logout'), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <button class="link-button" type="submit">ログアウト</button>
        </form>
    </nav>
</header>
<main class="admin-main">
    <?php if ($flash !== null): ?>
        <p class="flash flash-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?= $content ?>
</main>
</body>
</html>
