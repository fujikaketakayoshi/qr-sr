<h1>管理者ログイン</h1>
<?php if (isset($error)): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post" action="<?= htmlspecialchars($url('admin/login'), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <label>メールアドレス<input type="email" name="email" autocomplete="username" required value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
    <label>パスワード<input type="password" name="password" autocomplete="current-password" required></label>
    <button type="submit">ログイン</button>
</form>
<p><a href="<?= htmlspecialchars($url('admin/password/reset'), ENT_QUOTES, 'UTF-8') ?>">パスワードを忘れた場合</a></p>
