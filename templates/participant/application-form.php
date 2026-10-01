<?php
$values = $input?->values ?? ($existing ?? []);
$demoExamples = ['name' => '山田 デモ', 'email' => 'demo-user@example.test', 'address' => 'デモ県サンプル市1-2-3', 'phone' => '000-0000-0000'];
?>
<section class="panel">
    <h1><?= $existing ? '応募内容を編集' : '応募する' ?></h1>
    <?php if ($demoMode ?? false): ?><p class="demo-input-warning"><strong>デモ版の注意：</strong>入力内容は第三者に見られる可能性があります。本名・実在する住所・メールアドレス・電話番号は入力せず、必ず架空の情報を使用してください。</p><?php endif; ?>
    <?php require dirname(__DIR__) . '/form-error-summary.php'; ?>
    <form method="post" action="<?= htmlspecialchars($url('application/confirm'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach ($fields as $field): ?>
            <?php if (!$field['is_enabled']) continue; $type = $field['field_type']; $label = ['name' => '氏名', 'email' => 'メールアドレス', 'address' => '住所', 'phone' => '電話番号'][$type]; ?>
            <label><?= $label ?><?= $field['is_required'] ? '（必須）' : '（任意）' ?>
                <?php if ($type === 'address'): ?><textarea name="address" maxlength="500" rows="4"<?= ($demoMode ?? false) ? ' placeholder="' . htmlspecialchars($demoExamples[$type], ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($values[$type] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea><?php else: ?><input type="<?= $type === 'email' ? 'email' : 'text' ?>" name="<?= $type ?>" maxlength="<?= $type === 'name' ? 100 : ($type === 'email' ? 254 : 50) ?>" value="<?= htmlspecialchars($values[$type] ?? '', ENT_QUOTES, 'UTF-8') ?>"<?= ($demoMode ?? false) ? ' placeholder="' . htmlspecialchars($demoExamples[$type], ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?php endif; ?>
                <?php if (isset($errors[$type])): ?><small class="field-error"><?= htmlspecialchars($errors[$type], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
            </label>
            <?php if ($type === 'email'): ?><label>メールアドレス（確認）<input type="email" name="email_confirmation" maxlength="254" value="<?= htmlspecialchars($values['email_confirmation'] ?? ($values['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?php if (isset($errors['email_confirmation'])): ?><small class="field-error"><?= htmlspecialchars($errors['email_confirmation'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></label><?php endif; ?>
        <?php endforeach; ?>
        <div class="notice-text"><strong>個人情報の利用目的</strong><p><?= nl2br(htmlspecialchars($event['privacy_purpose_text'], ENT_QUOTES, 'UTF-8')) ?></p></div>
        <label class="checkbox"><input type="checkbox" name="privacy_accepted" value="1" required>個人情報の取り扱いに同意する</label>
        <?php if (isset($errors['privacy_accepted'])): ?><p class="field-error"><?= htmlspecialchars($errors['privacy_accepted'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($demoMode ?? false): ?><label class="checkbox"><input type="checkbox" name="demo_accepted" value="1" required<?= ($demoAccepted ?? false) ? ' checked' : '' ?>>公開デモであることを理解し、架空の情報だけを入力しています</label><?php if (isset($errors['demo_accepted'])): ?><p class="field-error"><?= htmlspecialchars($errors['demo_accepted'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?><?php endif; ?>
        <button>入力内容を確認</button>
    </form>
</section>
