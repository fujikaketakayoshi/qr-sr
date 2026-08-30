<div class="form-grid">
    <label>実行環境
        <select name="environment" required>
            <option value="production"<?= $values['environment'] === 'production' ? ' selected' : '' ?>>本番環境</option>
            <option value="development"<?= $values['environment'] === 'development' ? ' selected' : '' ?>>開発環境</option>
        </select>
        <?php $fieldError('environment'); ?>
    </label>
    <label>タイムゾーン
        <input name="timezone" value="<?= $h($values['timezone']) ?>" required>
        <?php $fieldError('timezone'); ?>
    </label>
    <label class="wide">ベースURL
        <span>この設置先のURL。末尾の / まで入力してください。</span>
        <input type="url" name="base_url" value="<?= $h($values['base_url']) ?>" required>
        <?php $fieldError('base_url'); ?>
    </label>
    <label class="wide">非公開領域の絶対パス
        <span>Webから公開されない領域を指定してください。既定名は qr-sr-private です。</span>
        <input name="private_path" value="<?= $h($values['private_path']) ?>" required>
        <?php $fieldError('private_path'); ?>
    </label>
</div>
