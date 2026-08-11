<div class="page-heading"><div><p class="eyebrow">ADMIN</p><h1>ダッシュボード</h1></div><a class="button" href="<?= htmlspecialchars($url('admin/event'), ENT_QUOTES, 'UTF-8') ?>">イベント設定</a></div>
<?php if ($event === null): ?>
    <section class="panel empty-state"><h2>イベントが未設定です</h2><p>名称、開催期間、達成条件を登録してください。</p></section>
<?php else: ?>
    <section class="panel">
        <span class="status-badge status-<?= htmlspecialchars($status->value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status->label(), ENT_QUOTES, 'UTF-8') ?></span>
        <h2><?= htmlspecialchars($event['name'], ENT_QUOTES, 'UTF-8') ?></h2>
        <p><?= nl2br(htmlspecialchars($event['description'], ENT_QUOTES, 'UTF-8')) ?></p>
        <dl class="summary"><div><dt>開始</dt><dd><?= htmlspecialchars($displayStartsAt, ENT_QUOTES, 'UTF-8') ?></dd></div><div><dt>終了</dt><dd><?= htmlspecialchars($displayEndsAt, ENT_QUOTES, 'UTF-8') ?></dd></div><div><dt>達成条件</dt><dd><?= (int) $event['required_stamp_count'] ?>個</dd></div></dl>
    </section>
<?php endif; ?>
