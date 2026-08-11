<h1>新しい復旧キー</h1>
<p class="flash flash-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
<p>以前の復旧キーは無効になりました。このキーは再表示できません。</p>
<pre class="recovery-key"><?= htmlspecialchars($recoveryKey, ENT_QUOTES, 'UTF-8') ?></pre>
<p><a class="button" href="<?= htmlspecialchars($url('admin/login'), ENT_QUOTES, 'UTF-8') ?>">ログインへ進む</a></p>
