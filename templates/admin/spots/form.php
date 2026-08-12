<div class="page-heading"><div><p class="eyebrow">SPOT</p><h1><?= $spot === null ? 'スポット追加' : 'スポット編集' ?></h1></div><a href="<?= htmlspecialchars($url('admin/spots'), ENT_QUOTES, 'UTF-8') ?>">一覧へ戻る</a></div>
<?php if ($errors !== []): ?><p class="error">入力内容を確認してください。</p><?php endif; ?>
<form class="panel" method="post" action="<?= htmlspecialchars($spot === null ? $url('admin/spots') : $url("admin/spots/{$spot['management_token']}/edit"), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <label>スポット名<span>必須・100文字以内</span><input name="name" maxlength="100" required value="<?= htmlspecialchars($values['name'], ENT_QUOTES, 'UTF-8') ?>"><?php if (isset($errors['name'])): ?><small class="field-error"><?= htmlspecialchars($errors['name'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></label>
    <label>説明<span>1000文字以内</span><textarea name="description" maxlength="1000" rows="5"><?= htmlspecialchars($values['description'], ENT_QUOTES, 'UTF-8') ?></textarea><?php if (isset($errors['description'])): ?><small class="field-error"><?= htmlspecialchars($errors['description'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></label>
    <button type="submit"><?= $spot === null ? '追加してQRを確認' : '変更を保存' ?></button>
</form>
