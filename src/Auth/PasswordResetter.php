<?php

declare(strict_types=1);

namespace QrRally\Auth;

use QrRally\Repository\AdminRepository;
use QrRally\Repository\AuditLogRepository;

final class PasswordResetter
{
    public function __construct(
        private readonly AdminRepository $admins,
        private readonly AuditLogRepository $logs,
        private readonly PasswordPolicy $passwords,
        private readonly RecoveryKey $recoveryKeys,
    ) {
    }

    /** @return array{success: bool, message: string, recovery_key?: string} */
    public function reset(string $email, string $recoveryKey, string $newPassword): array
    {
        $passwordError = $this->passwords->validate($newPassword);
        if ($passwordError !== null) {
            return ['success' => false, 'message' => $passwordError];
        }

        $admin = $this->admins->findByEmail($email);
        if ($admin === null || !password_verify($recoveryKey, (string) $admin['recovery_key_hash'])) {
            $this->logs->record('admin.password_reset', 'system', null, 'failed', ['reason' => 'invalid_credentials']);
            return ['success' => false, 'message' => 'メールアドレスまたは復旧キーが正しくありません。'];
        }

        $newRecoveryKey = $this->recoveryKeys->generate();
        $adminId = (int) $admin['id'];
        $this->admins->resetPassword(
            $adminId,
            password_hash($newPassword, PASSWORD_DEFAULT),
            password_hash($newRecoveryKey, PASSWORD_DEFAULT),
        );
        $this->logs->record('admin.password_reset', 'admin', $adminId, 'success');

        return [
            'success' => true,
            'message' => 'パスワードを変更しました。新しい復旧キーを保存してください。',
            'recovery_key' => $newRecoveryKey,
        ];
    }
}
