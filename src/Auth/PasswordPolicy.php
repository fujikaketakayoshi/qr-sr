<?php

declare(strict_types=1);

namespace QrRally\Auth;

final class PasswordPolicy
{
    public function validate(string $password): ?string
    {
        if (mb_strlen($password) < 12) {
            return 'パスワードは12文字以上で入力してください。';
        }

        if (mb_strlen($password) > 200) {
            return 'パスワードは200文字以内で入力してください。';
        }

        return null;
    }
}
