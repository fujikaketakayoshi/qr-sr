<?php

declare(strict_types=1);

namespace QrRally\Auth;

use DateTimeImmutable;
use DateTimeZone;
use QrRally\Repository\AdminRepository;
use QrRally\Repository\AuditLogRepository;
use QrRally\Repository\LoginAttemptRepository;
use QrRally\Security\CsrfToken;
use QrRally\Session\SessionManager;

final class AdminAuth
{
    private const MAX_FAILURES = 5;
    private const WINDOW_MINUTES = 15;

    public function __construct(
        private readonly AdminRepository $admins,
        private readonly LoginAttemptRepository $attempts,
        private readonly AuditLogRepository $logs,
        private readonly SessionManager $sessions,
        private readonly CsrfToken $csrf,
        private readonly string $appKey,
        private readonly int $sessionLifetime,
    ) {
    }

    /** @return array{success: bool, message: string} */
    public function attempt(string $email, string $password, string $clientIp): array
    {
        $normalizedEmail = mb_strtolower(trim($email));
        $identifier = hash_hmac('sha256', $normalizedEmail . '|' . $clientIp, $this->appKey);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $since = $now->modify('-' . self::WINDOW_MINUTES . ' minutes');
        $this->attempts->prune($now->modify('-1 day'));

        if ($this->attempts->countSince($identifier, $since) >= self::MAX_FAILURES) {
            $this->logs->record('admin.login', 'system', null, 'blocked', ['reason' => 'rate_limit']);
            return ['success' => false, 'message' => 'ログイン試行が多すぎます。15分ほど待ってからお試しください。'];
        }

        $admin = $this->admins->findByEmail($normalizedEmail);
        if ($admin === null || !password_verify($password, (string) $admin['password_hash'])) {
            $this->attempts->add($identifier);
            $this->logs->record('admin.login', 'system', null, 'failed', ['reason' => 'invalid_credentials']);
            return ['success' => false, 'message' => 'メールアドレスまたはパスワードが正しくありません。'];
        }

        $adminId = (int) $admin['id'];
        $this->attempts->clear($identifier);
        $this->sessions->regenerate();
        $this->csrf->rotate();
        $_SESSION['admin_id'] = $adminId;
        $_SESSION['authenticated_at'] = time();
        $_SESSION['last_activity_at'] = time();
        $_SESSION['auth_version'] = (int) $admin['auth_version'];
        $this->admins->recordLogin($adminId);
        $this->logs->record('admin.login', 'admin', $adminId, 'success');

        return ['success' => true, 'message' => 'ログインしました。'];
    }

    public function id(): ?int
    {
        $id = $_SESSION['admin_id'] ?? null;
        $lastActivity = $_SESSION['last_activity_at'] ?? null;
        $authVersion = $_SESSION['auth_version'] ?? null;
        if (!is_int($id) || !is_int($lastActivity) || !is_int($authVersion)
            || time() - $lastActivity > $this->sessionLifetime) {
            return null;
        }

        $admin = $this->admins->findById($id);
        if ($admin === null || (int) $admin['auth_version'] !== $authVersion) {
            return null;
        }

        $_SESSION['last_activity_at'] = time();

        return $id;
    }

    public function logout(): void
    {
        $id = $this->id();
        if ($id !== null) {
            $this->logs->record('admin.logout', 'admin', $id, 'success');
        }
        $this->sessions->destroy();
    }
}
