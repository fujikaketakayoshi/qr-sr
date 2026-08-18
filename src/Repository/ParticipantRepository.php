<?php

declare(strict_types=1);

namespace QrRally\Repository;

use PDO;
use PDOException;
use QrRally\Database\SqliteWriteRetrier;
use QrRally\Security\ParticipantToken;

final class ParticipantRepository
{
    private SqliteWriteRetrier $writes;

    public function __construct(
        private readonly PDO $database,
        private readonly ParticipantToken $tokens,
        ?SqliteWriteRetrier $writes = null,
    ) {
        $this->writes = $writes ?? new SqliteWriteRetrier();
    }

    public function create(string $token, string $nickname): int
    {
        $statement = $this->database->prepare(
            'INSERT INTO participants (token_hash, nickname, first_seen_at, last_seen_at, created_at, updated_at) '
            . "VALUES (:hash, :nickname, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))",
        );
        $this->writes->run(fn () => $statement->execute(['hash' => $this->tokens->hash($token), 'nickname' => $nickname]));

        return (int) $this->database->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        if (!$this->tokens->isValid($token)) {
            return null;
        }
        $statement = $this->database->prepare('SELECT * FROM participants WHERE token_hash = :hash LIMIT 1');
        $statement->execute(['hash' => $this->tokens->hash($token)]);
        $participant = $statement->fetch();
        if (!is_array($participant)) {
            return null;
        }
        $statement = $this->database->prepare("UPDATE participants SET last_seen_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = :id");
        $this->writes->run(fn () => $statement->execute(['id' => $participant['id']]));

        return $participant;
    }

    /** @return 'acquired'|'duplicate' */
    public function acquire(int $participantId, int $spotId, ?string $ipHash): string
    {
        return $this->writes->run(function () use ($participantId, $spotId, $ipHash): string {
            try {
                $statement = $this->database->prepare(
                    "INSERT INTO stamp_acquisitions (participant_id, spot_id, acquired_at, ip_hash) VALUES (:participant_id, :spot_id, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), :ip_hash)",
                );
                $statement->execute(['participant_id' => $participantId, 'spot_id' => $spotId, 'ip_hash' => $ipHash]);
                return 'acquired';
            } catch (PDOException $error) {
                if ((string) $error->getCode() === '23000') {
                    return 'duplicate';
                }
                throw $error;
            }
        });
    }

    public function acquisitionCount(int $participantId): int
    {
        $statement = $this->database->prepare('SELECT COUNT(*) FROM stamp_acquisitions WHERE participant_id = :id');
        $statement->execute(['id' => $participantId]);
        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function stampBoard(int $participantId): array
    {
        $statement = $this->database->prepare(
            'SELECT spots.id, spots.name, spots.description, spots.display_order, spots.is_active, stamp_acquisitions.acquired_at '
            . 'FROM spots LEFT JOIN stamp_acquisitions ON stamp_acquisitions.spot_id = spots.id AND stamp_acquisitions.participant_id = :participant_id '
            . 'ORDER BY spots.display_order, spots.id',
        );
        $statement->execute(['participant_id' => $participantId]);
        return $statement->fetchAll();
    }

    public function markCompletedIfEligible(int $participantId, int $requiredCount): bool
    {
        if ($this->acquisitionCount($participantId) < $requiredCount) {
            return false;
        }
        $statement = $this->database->prepare(
            "UPDATE participants SET completed_at = COALESCE(completed_at, strftime('%Y-%m-%dT%H:%M:%fZ', 'now')), updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = :id",
        );
        $this->writes->run(fn () => $statement->execute(['id' => $participantId]));
        return true;
    }
}
