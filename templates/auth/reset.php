<h1>パスワード再設定</h1>
<p>管理者メールアドレス、保存済みの復旧キー、新しいパスワードを入力してください。</p>
<?php if (isset($error)): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post" action="<?= htmlspecialchars($url('admin/password/reset'), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <label>メールアドレス<input type="email" name="email" required value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
    <label>復旧キー<input type="password" name="recovery_key" autocomplete="off" required></label>
    <label>新しいパスワード<input type="password" name="password" minlength="12" autocomplete="new-password" required></label>
    <label>新しいパスワード（確認）<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required></label>
    <button type="submit">パスワードを変更</button>
</form>
<p><a href="<?= htmlspecialchars($url('admin/login'), ENT_QUOTES, 'UTF-8') ?>">ログインへ戻る</a></p>
