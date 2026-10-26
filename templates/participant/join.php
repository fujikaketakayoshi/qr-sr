<section class="panel" aria-labelledby="nickname-title">
    <h2 id="nickname-title">ニックネーム（任意）</h2>
    <?php if ($demoMode ?? false): ?>
        <p class="demo-input-warning">本名や所属先など、個人を特定できる情報は入力しないでください。</p>
    <?php endif; ?>
    <form method="post" action="<?= htmlspecialchars($url('join'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <label for="nickname">ニックネーム（50文字以内）</label>
        <input id="nickname" name="nickname" maxlength="50" value="<?= htmlspecialchars($nickname ?? (string) $participant['nickname'], ENT_QUOTES, 'UTF-8') ?>">
        <?php if (isset($errors['nickname'])): ?>
            <p class="field-error" role="alert"><?= htmlspecialchars($errors['nickname'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <button type="submit">ニックネームを登録</button>
    </form>
</section>
