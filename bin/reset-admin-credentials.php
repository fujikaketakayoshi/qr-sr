<?php

declare(strict_types=1);

use QrRally\Auth\CredentialUpdater;
use QrRally\Auth\PasswordPolicy;
use QrRally\Auth\RecoveryKey;
use QrRally\Repository\AdminRepository;
use QrRally\Repository\AuditLogRepository;
use QrRally\Support\ConsoleInput;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

try {
    $container = require $root . '/bootstrap.php';
    $database = $container['database'];
    $admins = new AdminRepository($database);
    $admin = $admins->first();
    if ($admin === null) {
        throw new RuntimeException('管理者が登録されていません。先に初期設定を完了してください。');
    }

    $input = new ConsoleInput();
    $email = (string) $admin['email'];
    fwrite(STDOUT, "現在の管理者メールアドレス: {$email}\n");
    if (mb_strtolower($input->line('メールアドレスを変更しますか？ [y/N]: ')) === 'y') {
        $email = $input->line('新しいメールアドレス: ');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('正しい形式のメールアドレスを入力してください。');
        }
    }

    $password = $input->hidden('新しいパスワード（12文字以上）: ');
    $confirmation = $input->hidden('新しいパスワード（確認）: ');
    if (!hash_equals($password, $confirmation)) {
        throw new RuntimeException('確認用パスワードが一致しません。管理者情報は変更されていません。');
    }

    $passwordError = (new PasswordPolicy())->validate($password);
    if ($passwordError !== null) {
        throw new RuntimeException($passwordError . ' 管理者情報は変更されていません。');
    }

    $recoveryKey = (new CredentialUpdater(
        $database,
        $admins,
        new AuditLogRepository($database),
        new RecoveryKey(),
    ))->update(
        (int) $admin['id'],
        $email,
        $password,
        'admin_credentials_reset_via_cli',
        'system',
    );

    fwrite(STDOUT, "管理者認証情報を再設定しました。\n");
    fwrite(STDOUT, "新しい復旧キー（表示は今回限り）: {$recoveryKey}\n");
    fwrite(STDOUT, "復旧キーを安全な場所へ保存してください。以前のログインセッションは無効です。\n");
} catch (Throwable $error) {
    fwrite(STDERR, '再設定できませんでした: ' . $error->getMessage() . "\n");
    exit(1);
}
