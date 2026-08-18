<?php

declare(strict_types=1);

namespace QrRally\Repository;

use PDO;
use QrRally\Database\SqliteWriteRetrier;

final class AuditLogRepository
{
    private SqliteWriteRetrier $writes;

    public function __construct(private readonly PDO $database, ?SqliteWriteRetrier $writes = null)
    {
        $this->writes = $writes ?? new SqliteWriteRetrier();
    }

    /** @param array<string, bool|int|string|null> $context */
    public function record(
        string $eventType,
        string $actorType,
        ?int $actorId,
        string $result,
        array $context = [],
        ?string $targetType = null,
        ?int $targetId = null,
    ): void {
        $statement = $this->database->prepare(
            'INSERT INTO audit_logs '
            . '(event_type, actor_type, actor_id, target_type, target_id, result, context_json, created_at) '
            . "VALUES (:event_type, :actor_type, :actor_id, :target_type, :target_id, :result, :context_json, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))",
        );
        $values = [
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'result' => $result,
            'context_json' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
        $this->writes->run(fn () => $statement->execute($values));
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 100): array
    {
        $limit = max(1, min($limit, 200));
        $statement = $this->database->query(
            'SELECT id, event_type, actor_type, actor_id, target_type, target_id, result, context_json, created_at '
            . "FROM audit_logs ORDER BY id DESC LIMIT {$limit}",
        );

        return $statement->fetchAll();
    }
}
