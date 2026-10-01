<?php if ($demoMode ?? false): ?>
    <aside class="demo-banner" role="note">
        <strong>公開デモ環境です</strong>
        <p>入力内容は第三者に見られる可能性があります。本名・実在する住所・メールアドレス・電話番号・業務上の秘密などは入力せず、必ず架空の情報を使用してください。</p>
        <p>データは毎時00分に初期化されます。次回の初期化予定：<?= htmlspecialchars($demoNextResetAt, ENT_QUOTES, 'UTF-8') ?></p>
    </aside>
<?php endif; ?>
