<?php if (!$event): ?>
<section class="panel empty-state">
    <h1>準備中です</h1>
    <p>イベントが設定されるまでお待ちください。</p>
</section>
<?php else: ?>
<section class="participant-hero panel">
    <span class="status-badge status-<?= htmlspecialchars($eventStatus->value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($eventStatus->label(), ENT_QUOTES, 'UTF-8') ?></span>
    <h1><?= htmlspecialchars($event['name'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p><?= nl2br(htmlspecialchars($event['description'], ENT_QUOTES, 'UTF-8')) ?></p>
    <dl class="participant-period">
        <div>
            <dt>開催期間</dt>
            <dd><?= htmlspecialchars($displayStartsAt, ENT_QUOTES, 'UTF-8') ?> ～ <?= htmlspecialchars($displayEndsAt, ENT_QUOTES, 'UTF-8') ?></dd>
        </div>
    </dl>
    <p><strong><?= htmlspecialchars($participant['nickname'], ENT_QUOTES, 'UTF-8') ?></strong> さんのスタンプ：<strong><?= (int) $acquisitionCount ?> / <?= (int) $event['required_stamp_count'] ?></strong></p>
    <?php if ($eventStatus->value === 'paused'): ?>
        <p class="error"><?= nl2br(htmlspecialchars($event['pause_message'] ?: 'イベントは現在一時停止中です。', ENT_QUOTES, 'UTF-8')) ?></p>
    <?php endif; ?>
    <?php if ($completed): ?>
        <div class="completion">
            <h2>達成おめでとうございます！</h2>
            <p><?= nl2br(htmlspecialchars($event['completion_message'], ENT_QUOTES, 'UTF-8')) ?></p>
            <?php if ($application !== null): ?>
                <p><strong>応募済みです</strong></p>
                <dl class="application-status">
                    <div>
                        <dt>応募番号</dt>
                        <dd><?= htmlspecialchars((string) $application['application_number'], ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                </dl>
                <?php if ($applicationEditable): ?>
                    <p class="application-deadline"><strong>応募締切</strong><span><?= htmlspecialchars($displayApplicationDeadline, ENT_QUOTES, 'UTF-8') ?></span></p>
                    <p><a class="button" href="<?= htmlspecialchars($url('application'), ENT_QUOTES, 'UTF-8') ?>">応募内容を確認・編集する</a></p>
                <?php else: ?>
                    <?php if ($applicationDeadlinePassed): ?><p>応募受付は終了しました。</p><?php endif; ?>
                    <p><a class="button" href="<?= htmlspecialchars($url('application'), ENT_QUOTES, 'UTF-8') ?>">応募内容を確認する</a></p>
                <?php endif; ?>
            <?php elseif ($showApplicationLink): ?>
                <p class="application-deadline"><strong>応募締切</strong><span><?= htmlspecialchars($displayApplicationDeadline, ENT_QUOTES, 'UTF-8') ?></span></p>
                <p><a class="button" href="<?= htmlspecialchars($url('application'), ENT_QUOTES, 'UTF-8') ?>">応募フォームを開く</a></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
<section>
    <h2>スタンプ台紙</h2>
    <div class="stamp-grid">
        <?php foreach ($spots as $spot): ?>
            <?php $acquired = $spot['acquired_at'] !== null; ?>
            <article class="stamp-card <?= $acquired ? 'stamp-acquired' : '' ?>">
                <span class="spot-number"><?= sprintf('%02d', (int) $spot['display_order']) ?></span>
                <h3><?= htmlspecialchars($spot['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                <p><?= $acquired ? '取得済み' : ((bool) $spot['is_active'] ? '未取得' : '停止中') ?></p>
                <?php if ($acquired): ?><span class="stamp-mark">GET</span><?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
