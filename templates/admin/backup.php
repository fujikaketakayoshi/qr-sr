<div class="page-heading">
    <div><p class="eyebrow">MAINTENANCE</p><h1>バックアップ・復元</h1></div>
</div>

<?php if ($error !== null): ?>
    <p class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="panel maintenance-section">
    <h2>バックアップを作成</h2>
    <dl class="summary summary-four">
        <div><dt>対象イベント</dt><dd><?= htmlspecialchars((string) ($event['name'] ?? '未設定'), ENT_QUOTES, 'UTF-8') ?></dd></div>
        <div><dt>参加者</dt><dd><?= (int) $summary['participants'] ?>人</dd></div>
        <div><dt>応募者</dt><dd><?= (int) $summary['applications'] ?>人</dd></div>
        <div><dt>現在のDB容量</dt><dd><?= htmlspecialchars($estimatedSize, ENT_QUOTES, 'UTF-8') ?></dd></div>
    </dl>
    <p class="warning-text">バックアップには管理者情報や応募者の個人情報が含まれます。安全な場所へ保管し、不要になったファイルは削除してください。</p>
    <form method="post" action="<?= htmlspecialchars($url('admin/backup/download'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <label>現在の管理者パスワード<input type="password" name="password" autocomplete="current-password" required></label>
        <button type="submit">バックアップを作成してダウンロード</button>
    </form>
</section>

<section class="panel maintenance-section">
    <h2>バックアップを復元</h2>
    <p class="warning-text">復元すると現在のデータはバックアップ作成時点へ戻ります。復元後は、バックアップ作成時点の管理者パスワードで再ログインする必要があります。</p>
    <form method="post" enctype="multipart/form-data" action="<?= htmlspecialchars($url('admin/backup/upload'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="104857600">
        <label>バックアップZIP<input type="file" name="backup_file" accept=".zip,application/zip" required></label>
        <label>現在の管理者パスワード<input type="password" name="password" autocomplete="current-password" required></label>
        <button class="secondary" type="submit">アップロードして検証</button>
    </form>
</section>

<?php if ($manifest !== null && ($state['status'] ?? null) === 'ready'): ?>
    <section class="panel maintenance-section restore-confirmation">
        <h2>復元内容の確認</h2>
        <p class="result-message result-success">形式、ハッシュ、SQLite、テーブル件数、外部キーの検査に合格しました。</p>
        <dl class="status">
            <div><dt>作成日時</dt><dd><?= htmlspecialchars((string) $manifest['created_at'], ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt>製品バージョン</dt><dd><?= htmlspecialchars((string) $manifest['product_version'], ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt>スキーマバージョン</dt><dd><?= htmlspecialchars((string) $manifest['schema_version'], ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt>バックアップ元URL</dt><dd><?= htmlspecialchars((string) $manifest['source_base_url'], ENT_QUOTES, 'UTF-8') ?></dd></div>
            <div><dt>スポット</dt><dd><?= (int) ($manifest['table_counts']['spots'] ?? 0) ?>件</dd></div>
            <div><dt>参加者</dt><dd><?= (int) ($manifest['table_counts']['participants'] ?? 0) ?>人</dd></div>
            <div><dt>応募者</dt><dd><?= (int) ($manifest['table_counts']['applications'] ?? 0) ?>人</dd></div>
        </dl>
        <?php if ($sourceUrlDiffers): ?>
            <p class="warning-text">バックアップ元と現在のURLが異なります。URL設定は復元されず、現在のconfig.phpが維持されます。</p>
        <?php endif; ?>
        <form method="post" action="<?= htmlspecialchars($url('admin/backup/restore'), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <label>確認のため「復元する」と入力<input type="text" name="confirmation" autocomplete="off" required></label>
            <label>現在の管理者パスワード<input type="password" name="password" autocomplete="current-password" required></label>
            <button class="danger" type="submit">現在のデータを置き換えて復元</button>
        </form>
    </section>
<?php elseif (($state['status'] ?? null) === 'failed'): ?>
    <section class="panel maintenance-section"><h2>直前の復元結果</h2><p class="error"><?= htmlspecialchars((string) ($state['error_summary'] ?? '復元に失敗しました。'), ENT_QUOTES, 'UTF-8') ?></p><p>現在のデータは維持されています。</p></section>
<?php elseif (($state['status'] ?? null) === 'completed'): ?>
    <section class="panel maintenance-section"><h2>直前の復元結果</h2><p class="result-message result-success">復元が完了しました。</p></section>
<?php endif; ?>

<?php if ($operations !== []): ?>
    <section class="panel maintenance-section">
        <h2>最近の処理</h2>
        <div class="table-wrap"><table><thead><tr><th>日時</th><th>種類</th><th>結果</th></tr></thead><tbody>
        <?php foreach ($operations as $operation): ?><tr>
            <td><?= htmlspecialchars((string) $operation['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= $operation['operation_type'] === 'backup' ? 'バックアップ' : '復元' ?></td>
            <td><?= htmlspecialchars((string) $operation['status'], ENT_QUOTES, 'UTF-8') ?><?php if ($operation['error_summary'] !== null): ?><br><?= htmlspecialchars((string) $operation['error_summary'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
<?php endif; ?>
