<h1>インストールが完了しました</h1>
<p class="flash flash-success">DBと設定ファイルを作成しました。</p>
<?php $diagnosticLabels = [
    'directory' => 'ディレクトリ', 'file' => 'ファイル作成・読取', 'sqlite' => 'SQLite',
    'crud' => '作成・書込・読取・削除', 'upsert' => 'UPSERT', 'json' => 'JSON関数',
    'foreign_key' => '外部キー制約', 'wal' => 'WALモード', 'lock' => 'ロック動作',
    'cleanup' => '診断ファイルの削除',
]; ?>
<section class="panel install-section">
    <h2>設置結果</h2>
    <dl class="status">
        <div><dt>ベースURL</dt><dd><?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?></dd></div>
        <div><dt>非公開領域</dt><dd><?= htmlspecialchars($privatePath, ENT_QUOTES, 'UTF-8') ?></dd></div>
        <?php foreach ($pathDiagnostics as $item => $passed): ?>
            <div><dt><?= htmlspecialchars($diagnosticLabels[$item] ?? $item, ENT_QUOTES, 'UTF-8') ?></dt><dd><?= $passed ? 'OK' : 'NG' ?></dd></div>
        <?php endforeach; ?>
    </dl>
</section>
<section class="panel install-section">
    <h2>復旧キーを保存してください</h2>
    <p class="warning-text"><strong>この復旧キーは、この画面を離れると二度と表示できません。</strong> 管理者パスワードを忘れたときに必要です。</p>
    <pre class="recovery-key" id="recovery-key"><?= htmlspecialchars($recoveryKey, ENT_QUOTES, 'UTF-8') ?></pre>
    <p>紛失した場合、このキーの再表示はできません。必要ならSSHから緊急認証再設定コマンドを使用します。</p>
    <label class="checkbox"><input type="checkbox" id="saved-key"> 復旧キーを安全な場所へ保存しました</label>
    <div class="install-actions">
        <button type="button" id="download-key">復旧キーをダウンロード</button>
        <button type="button" class="secondary" id="go-login" disabled>管理画面へ進む</button>
    </div>
</section>
<script>
document.getElementById('download-key').addEventListener('click', function () {
    const key = document.getElementById('recovery-key').textContent;
    const blob = new Blob(['QRコードスタンプラリー 復旧キー\n\n' + key + '\n'], {type: 'text/plain;charset=utf-8'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'qr-rally-recovery-key.txt';
    link.click();
    URL.revokeObjectURL(link.href);
});
const savedKey = document.getElementById('saved-key');
const loginButton = document.getElementById('go-login');
savedKey.addEventListener('change', function () { loginButton.disabled = !savedKey.checked; });
loginButton.addEventListener('click', function () { window.location.href = <?= json_encode($loginUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>; });
</script>
