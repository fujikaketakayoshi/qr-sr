<?php if ($demoMode ?? false): ?>
    <aside class="demo-banner" role="note">
        <strong>公開デモ環境です</strong>
        <p>入力内容は第三者にも見られます。実在する個人情報は入力しないでください。</p>
        <p>データは毎時00分に初期化されます。次回の初期化予定：<?= htmlspecialchars($demoNextResetAt, ENT_QUOTES, 'UTF-8') ?></p>
    </aside>
<?php endif; ?>
