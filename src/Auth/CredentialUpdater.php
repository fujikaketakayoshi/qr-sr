<?php

declare(strict_types=1);

namespace QrRally\Auth;

use PDO;
use QrRally\Repository\AdminRepository;
use QrRally\Repository\AuditLogRepository;
use Throwable;

final class CredentialUpdater
{
    public function __construct(
        private readonly PDO $database,
        private readonly AdminRepository $admins,
        private readonly AuditLogRepository $logs,
        private readonly RecoveryKey $recoveryKeys,
    ) {
    }

    public function update(
        int $adminId,
        string $email,
        string $newPassword,
        string $eventType,
        string $actorType,
    ): string {
        $newRecoveryKey = $this->recoveryKeys->generate();
        $this->database->beginTransaction();
        try {
            $this->admins->updateCredentials(
                $adminId,
                $email,
                password_hash($newPassword, PASSWORD_DEFAULT),
                password_hash($newRecoveryKey, PASSWORD_DEFAULT),
            );
            $this->logs->record(
                $eventType,
                $actorType,
                $actorType === 'admin' ? $adminId : null,
                'success',
                [],
                'admin',
                $adminId,
            );
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }

        return $newRecoveryKey;
    }
}
