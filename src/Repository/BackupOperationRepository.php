<?php

declare(strict_types=1);

namespace QrRally\Repository;

use PDO;

final class BackupOperationRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function record(string $type, string $status, string $hash, ?string $error = null): int
    {
        $statement = $this->database->prepare(
            'INSERT INTO backup_operations '
            . '(operation_type, status, backup_version, file_hash, error_summary, created_at, updated_at) '
            . "VALUES (:type, :status, '1', :hash, :error, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))",
        );
        $statement->execute(['type' => $type, 'status' => $status, 'hash' => $hash, 'error' => $error]);
        return (int) $this->database->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function recent(): array
    {
        return $this->database->query(
            'SELECT operation_type, status, file_hash, error_summary, created_at, updated_at '
            . 'FROM backup_operations ORDER BY id DESC LIMIT 10',
        )->fetchAll();
    }
}
