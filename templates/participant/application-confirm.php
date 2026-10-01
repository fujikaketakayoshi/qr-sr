<section class="panel">
    <h1>応募内容の確認</h1>
    <?php if ($demoMode ?? false): ?><p class="demo-input-warning">公開デモ用の架空情報であることを確認してから応募してください。この内容は毎時00分の初期化まで第三者に表示される可能性があります。</p><?php endif; ?>
    <dl class="status"><?php foreach ($fields as $field): ?><?php if (!$field['is_enabled']) continue; $type = $field['field_type']; ?><div><dt><?= ['name' => '氏名', 'email' => 'メールアドレス', 'address' => '住所', 'phone' => '電話番号'][$type] ?></dt><dd><?= nl2br(htmlspecialchars($input->values[$type] ?: '（未入力）', ENT_QUOTES, 'UTF-8')) ?></dd></div><?php endforeach; ?></dl>
    <p>個人情報の取り扱いへの同意：同意済み</p>
    <?php if ($demoMode ?? false): ?><p>公開デモの注意事項：確認済み</p><?php endif; ?>
    <form method="post" action="<?= htmlspecialchars($url('application/submit'), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><button>この内容で応募する</button> <a class="button secondary" href="<?= htmlspecialchars($url('application'), ENT_QUOTES, 'UTF-8') ?>">入力へ戻る</a></form>
</section>
