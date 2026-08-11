<?php

declare(strict_types=1);

namespace QrRally\Repository;

use PDO;

final class AdminRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM admins WHERE email = :email LIMIT 1');
        $statement->execute(['email' => mb_strtolower(trim($email))]);
        $admin = $statement->fetch();

        return is_array($admin) ? $admin : null;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM admins WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $admin = $statement->fetch();

        return is_array($admin) ? $admin : null;
    }

    public function count(): int
    {
        return (int) $this->database->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    }

    public function create(string $email, string $passwordHash, string $recoveryKeyHash): int
    {
        $statement = $this->database->prepare(
            'INSERT INTO admins (email, password_hash, recovery_key_hash, created_at, updated_at) '
            . "VALUES (:email, :password_hash, :recovery_key_hash, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))",
        );
        $statement->execute([
            'email' => mb_strtolower(trim($email)),
            'password_hash' => $passwordHash,
            'recovery_key_hash' => $recoveryKeyHash,
        ]);

        return (int) $this->database->lastInsertId();
    }

    public function recordLogin(int $id): void
    {
        $statement = $this->database->prepare(
            "UPDATE admins SET last_login_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), "
            . "updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = :id",
        );
        $statement->execute(['id' => $id]);
    }

    public function resetPassword(int $id, string $passwordHash, string $recoveryKeyHash): void
    {
        $statement = $this->database->prepare(
            'UPDATE admins SET password_hash = :password_hash, recovery_key_hash = :recovery_key_hash, '
            . "updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = :id",
        );
        $statement->execute([
            'id' => $id,
            'password_hash' => $passwordHash,
            'recovery_key_hash' => $recoveryKeyHash,
        ]);
    }
}
