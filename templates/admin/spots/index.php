<div class="page-heading"><div><p class="eyebrow">SPOTS</p><h1>スポット管理</h1></div><a class="button" href="<?= htmlspecialchars($url('admin/spots/create'), ENT_QUOTES, 'UTF-8') ?>">スポットを追加</a></div>
<?php if ($event === null): ?><p class="error">先にイベント設定を作成してください。</p><?php endif; ?>
<?php if ($spots === []): ?>
<section class="panel empty-state"><h2>スポットがありません</h2><p>最初のスポットを追加すると、QRコードを確認できます。</p></section>
<?php else: ?>
<div class="spot-list">
<?php foreach ($spots as $index => $spot): ?>
<article class="panel spot-card <?= $spot['is_active'] ? '' : 'spot-inactive' ?>">
    <div class="spot-order">
        <form method="post" action="<?= htmlspecialchars($url("admin/spots/{$spot['management_token']}/move"), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="direction" value="up"><button class="small secondary" type="submit" <?= $index === 0 ? 'disabled' : '' ?>>↑</button></form>
        <form method="post" action="<?= htmlspecialchars($url("admin/spots/{$spot['management_token']}/move"), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="direction" value="down"><button class="small secondary" type="submit" <?= $index === count($spots) - 1 ? 'disabled' : '' ?>>↓</button></form>
    </div>
    <div class="spot-details"><div><span class="status-badge <?= $spot['is_active'] ? 'status-active' : 'status-paused' ?>"><?= $spot['is_active'] ? '有効' : '停止中' ?></span><h2><?= htmlspecialchars($spot['name'], ENT_QUOTES, 'UTF-8') ?></h2></div><p><?= nl2br(htmlspecialchars($spot['description'], ENT_QUOTES, 'UTF-8')) ?></p><small>取得履歴 <?= (int) $spot['acquisition_count'] ?>件</small></div>
    <div class="spot-actions">
        <a class="button small" href="<?= htmlspecialchars($url("admin/spots/{$spot['management_token']}/qr"), ENT_QUOTES, 'UTF-8') ?>">QR確認</a>
        <a class="button small secondary" href="<?= htmlspecialchars($url("admin/spots/{$spot['management_token']}/edit"), ENT_QUOTES, 'UTF-8') ?>">編集</a>
        <form method="post" action="<?= htmlspecialchars($url("admin/spots/{$spot['management_token']}/toggle"), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><button class="small secondary" type="submit"><?= $spot['is_active'] ? '停止' : '再開' ?></button></form>
        <?php if ($canDelete && (int) $spot['acquisition_count'] === 0): ?><?php $fallsBelowRequired = count($spots) - 1 < (int) $event['required_stamp_count']; ?><form method="post" action="<?= htmlspecialchars($url("admin/spots/{$spot['management_token']}/delete"), ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm('<?= $fallsBelowRequired ? 'このスポットを削除すると、残りのスポット数が達成条件を下回り、新規参加者が達成できなくなります。削除後にイベント設定の達成条件を見直す必要があります。それでも削除しますか？' : 'このスポットを削除しますか？' ?>');"><input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><button class="small danger" type="submit">削除</button></form><?php endif; ?>
    </div>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>
<aside class="panel notice"><h2>停止と削除について</h2><p>取得履歴があるスポットは削除できません。停止後も過去の取得実績は達成数に残ります。削除できるのは、開催前かつ取得履歴がないスポットだけです。</p><?php if ($event !== null): ?><?php $activeCount = count(array_filter($spots, fn ($spot) => (bool) $spot['is_active'])); ?><p>現在の有効スポット数: <strong><?= $activeCount ?></strong> ／ 達成条件: <strong><?= (int) $event['required_stamp_count'] ?></strong></p><?php if ($activeCount < (int) $event['required_stamp_count']): ?><p class="error">新規参加者が達成できない状態です。スポットを再開するか、イベント設定の達成条件を見直してください。</p><?php endif; ?><?php endif; ?></aside>
