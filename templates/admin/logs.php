<div class="page-heading"><div><p class="eyebrow">AUDIT</p><h1>操作ログ</h1></div></div>
<div class="table-wrap"><table><thead><tr><th>日時（UTC）</th><th>種類</th><th>結果</th><th>実行者</th><th>補足</th></tr></thead><tbody>
<?php foreach ($logs as $log): ?><tr><td><?= htmlspecialchars($log['created_at'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($log['event_type'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($log['result'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($log['actor_type'], ENT_QUOTES, 'UTF-8') ?></td><td><code><?= htmlspecialchars($log['context_json'], ENT_QUOTES, 'UTF-8') ?></code></td></tr><?php endforeach; ?>
<?php if ($logs === []): ?><tr><td colspan="5">操作ログはありません。</td></tr><?php endif; ?>
</tbody></table></div>
