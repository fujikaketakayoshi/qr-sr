<?php
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$fieldError = static function (string $name) use ($errors, $h): void {
    if (isset($errors[$name])) {
        echo '<span class="field-error">' . $h($errors[$name]) . '</span>';
    }
};
$diagnosticLabels = [
    'directory' => 'ディレクトリ', 'file' => 'ファイル作成・読取', 'sqlite' => 'SQLite',
    'wal' => 'WALモード', 'lock' => 'ロック動作', 'cleanup' => '診断ファイルの削除',
];
?>
<h1>セットアップ</h1>
<p>新規インストール、またはこの製品で作成したバックアップから新しい環境へ復元できます。</p>
<p class="warning-text"><strong>ファイルをサーバーへ配置したら、第三者に先に設定されないよう続けてセットアップを完了してください。</strong></p>

<?php if ($errors !== []): ?>
<div class="flash flash-error" role="alert">
    <strong>入力内容にエラーがあります。</strong>
    <span>各項目の赤いメッセージを確認してください。</span>
    <?php if (isset($errors['form'])): ?><span><?= $h($errors['form']) ?></span><?php endif; ?>
</div>
<?php endif; ?>

<section class="panel install-section">
    <h2>環境診断</h2>
    <dl class="status install-status">
        <div><dt>PHP 8.3以上</dt><dd><?= $diagnostics['php'] ? 'OK（' . $h(PHP_VERSION) . '）' : 'NG（' . $h(PHP_VERSION) . '）' ?></dd></div>
        <?php foreach ($diagnostics['extensions'] as $extension => $loaded): ?>
            <div><dt><?= $h($extension) ?></dt><dd><?= $loaded ? 'OK' : 'NG' ?></dd></div>
        <?php endforeach; ?>
        <?php foreach ($diagnostics['recommended_extensions'] as $extension => $loaded): ?>
            <div><dt><?= $h($extension) ?>（推奨）</dt><dd><?= $loaded ? 'OK' : '未使用' ?></dd></div>
        <?php endforeach; ?>
        <?php foreach ($diagnostics['optional_extensions'] as $extension => $loaded): ?>
            <div><dt><?= $h($extension) ?>（任意）</dt><dd><?= $loaded ? 'OK' : '未使用' ?></dd></div>
        <?php endforeach; ?>
        <div><dt>HTTPS</dt><dd><?= $diagnostics['https'] ? 'OK' : '未使用' ?></dd></div>
    </dl>
    <?php if (!$diagnostics['requirements_met']): ?>
        <p class="error">必須環境を満たしていません。NGになっている項目をサーバー側で有効にしてください。</p>
    <?php elseif (!$diagnostics['https']): ?>
        <p class="warning-text">HTTPを検出しました。本番運用ではHTTPSが必須です。HTTPはlocalhostまたはLAN内での開発時だけ選択できます。</p>
    <?php endif; ?>
</section>

<?php if ($restoreState !== null): ?>
<section class="panel install-section restore-confirmation">
    <h2>復元内容の確認</h2>
    <p>ZIPの検証に成功しました。復元すると、バックアップ内の管理者アカウントとイベントデータを引き継ぎます。</p>
    <dl class="status">
        <div><dt>バックアップ作成日時</dt><dd><?= $h((string) $restoreState['manifest']['created_at']) ?></dd></div>
        <div><dt>移転元URL</dt><dd><?= $h((string) $restoreState['manifest']['source_base_url']) ?></dd></div>
        <div><dt>新しいURL</dt><dd><?= $h($values['base_url']) ?></dd></div>
        <?php foreach (($restoreState['path_diagnostics'] ?? []) as $item => $passed): ?>
            <div><dt>非公開領域: <?= $h($diagnosticLabels[$item] ?? $item) ?></dt><dd><?= $passed ? 'OK' : 'NG' ?></dd></div>
        <?php endforeach; ?>
    </dl>
    <?php if ((string) $restoreState['manifest']['source_base_url'] !== $values['base_url']): ?>
        <p class="warning-text"><strong>ベースURLが変わります。</strong>イベント入口とすべてのスポットのQRコードは、移転先ではURLが変わります。移転後に新しい管理画面から再印刷し、印刷・掲示・配布済みのものをすべて交換してください。</p>
    <?php endif; ?>
    <form method="post" action="">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="action" value="execute_restore">
        <label>確認入力
            <span>「復元する」と入力してください。</span>
            <input name="confirmation" autocomplete="off" required>
            <?php $fieldError('confirmation'); ?>
        </label>
        <button class="danger" type="submit">新しい環境へ復元する</button>
    </form>
    <form method="post" action="" class="install-cancel-form">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="action" value="cancel_restore">
        <button class="secondary" type="submit">キャンセルして選び直す</button>
    </form>
</section>
<?php else: ?>
<section class="panel install-section">
    <h2>新規インストール</h2>
    <form method="post" action="">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="action" value="new_install">
        <?php require __DIR__ . '/settings-fields.php'; ?>
        <label>管理者メールアドレス
            <input type="email" name="email" value="<?= $h($values['email']) ?>" required autocomplete="username">
            <?php $fieldError('email'); ?>
        </label>
        <div class="form-grid">
            <label>管理者パスワード
                <span>12文字以上</span>
                <input type="password" name="password" required autocomplete="new-password">
                <?php $fieldError('password'); ?>
            </label>
            <label>管理者パスワード（確認）
                <input type="password" name="password_confirmation" required autocomplete="new-password">
                <?php $fieldError('password_confirmation'); ?>
            </label>
        </div>
        <button type="submit"<?= $diagnostics['requirements_met'] ? '' : ' disabled' ?>>インストールする</button>
    </form>
</section>

<section class="panel install-section">
    <h2>バックアップから新しい環境へ復元</h2>
    <p>旧環境の管理画面で作成したバックアップZIPを使用します。管理者アカウントもバックアップ内のものを引き継ぎます。</p>
    <form method="post" action="" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="action" value="validate_restore">
        <?php require __DIR__ . '/settings-fields.php'; ?>
        <label>バックアップZIP
            <span>100MB以下。この製品で作成したZIPだけを使用できます。</span>
            <input type="file" name="backup" accept=".zip,application/zip" required>
            <?php $fieldError('backup'); ?>
        </label>
        <button type="submit"<?= $diagnostics['requirements_met'] ? '' : ' disabled' ?>>ZIPを検証して確認へ進む</button>
    </form>
</section>
<?php endif; ?>
