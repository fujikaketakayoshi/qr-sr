<h1>管理者ログイン</h1>
<?php if ($restored ?? false): ?><p class="flash flash-success">バックアップの復元が完了しました。バックアップ作成時点の管理者パスワードでログインしてください。</p><?php endif; ?>
<?php if (isset($error)): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post" action="<?= htmlspecialchars($url('admin/login') . (($restored ?? false) ? '?restored=1' : ''), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <label>メールアドレス<input type="email" name="email" autocomplete="username" required value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
    <label>パスワード<input type="password" name="password" autocomplete="current-password" required></label>
    <button type="submit">ログイン</button>
</form>
<?php if ($demoMode ?? false): ?>
    <p class="demo-disabled">公開デモでは、復旧キーを使ったパスワード再設定は利用できません。</p>
<?php else: ?>
    <p><a href="<?= htmlspecialchars($url('admin/password/reset'), ENT_QUOTES, 'UTF-8') ?>">パスワードを忘れた場合</a></p>
<?php endif; ?>
