<?php

declare(strict_types=1);

namespace QrRally\Repository;

use DateTimeImmutable;
use PDO;

final class LoginAttemptRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function countSince(string $identifierHash, DateTimeImmutable $since): int
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM admin_login_attempts '
            . 'WHERE identifier_hash = :identifier_hash AND attempted_at >= :since',
        );
        $statement->execute([
            'identifier_hash' => $identifierHash,
            'since' => $since->format('Y-m-d\TH:i:s.v\Z'),
        ]);

        return (int) $statement->fetchColumn();
    }

    public function add(string $identifierHash): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO admin_login_attempts (identifier_hash, attempted_at) '
            . "VALUES (:identifier_hash, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))",
        );
        $statement->execute(['identifier_hash' => $identifierHash]);
    }

    public function clear(string $identifierHash): void
    {
        $statement = $this->database->prepare(
            'DELETE FROM admin_login_attempts WHERE identifier_hash = :identifier_hash',
        );
        $statement->execute(['identifier_hash' => $identifierHash]);
    }

    public function prune(DateTimeImmutable $before): void
    {
        $statement = $this->database->prepare(
            'DELETE FROM admin_login_attempts WHERE attempted_at < :before',
        );
        $statement->execute(['before' => $before->format('Y-m-d\TH:i:s.v\Z')]);
    }
}
