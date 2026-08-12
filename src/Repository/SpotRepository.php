<?php

declare(strict_types=1);

namespace QrRally\Repository;

use PDO;
use QrRally\Domain\SpotInput;
use QrRally\Security\SpotToken;
use RuntimeException;
use Throwable;

final class SpotRepository
{
    public function __construct(
        private readonly PDO $database,
        private readonly SpotToken $tokens,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $spots = $this->database->query(
            'SELECT spots.*, COUNT(stamp_acquisitions.id) AS acquisition_count '
            . 'FROM spots LEFT JOIN stamp_acquisitions ON stamp_acquisitions.spot_id = spots.id '
            . 'GROUP BY spots.id ORDER BY spots.display_order, spots.id',
        )->fetchAll();

        return array_map(fn (array $spot): array => $this->withManagementToken($spot), $spots);
    }

    public function count(): int
    {
        return (int) $this->database->query('SELECT COUNT(*) FROM spots')->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->database->prepare(
            'SELECT spots.*, COUNT(stamp_acquisitions.id) AS acquisition_count '
            . 'FROM spots LEFT JOIN stamp_acquisitions ON stamp_acquisitions.spot_id = spots.id '
            . 'WHERE spots.id = :id GROUP BY spots.id',
        );
        $statement->execute(['id' => $id]);
        $spot = $statement->fetch();

        return is_array($spot) ? $this->withManagementToken($spot) : null;
    }

    /** @return array<string, mixed>|null */
    public function findByPublicToken(string $token): ?array
    {
        $statement = $this->database->prepare(
            'SELECT * FROM spots WHERE public_token_hash = :hash LIMIT 1',
        );
        $statement->execute(['hash' => $this->tokens->hash($token)]);
        $spot = $statement->fetch();

        return is_array($spot) ? $spot : null;
    }

    public function create(SpotInput $input): int
    {
        $this->database->beginTransaction();
        try {
            $order = (int) $this->database->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM spots')->fetchColumn();
            $temporaryHash = hash('sha256', random_bytes(32));
            $statement = $this->database->prepare(
                'INSERT INTO spots (public_token_hash, name, description, display_order, is_active, created_at, updated_at) '
                . "VALUES (:hash, :name, :description, :display_order, 1, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))",
            );
            $statement->execute([
                'hash' => $temporaryHash,
                'name' => $input->name,
                'description' => $input->description,
                'display_order' => $order,
            ]);
            $id = (int) $this->database->lastInsertId();
            $this->setTokenHash($id, 1);
            $this->database->commit();

            return $id;
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    public function update(int $id, SpotInput $input): void
    {
        $statement = $this->database->prepare(
            'UPDATE spots SET name = :name, description = :description, '
            . "updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = :id",
        );
        $statement->execute(['id' => $id, 'name' => $input->name, 'description' => $input->description]);
    }

    public function setActive(int $id, bool $active): void
    {
        $statement = $this->database->prepare(
            'UPDATE spots SET is_active = :active, '
            . "updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = :id",
        );
        $statement->execute(['id' => $id, 'active' => $active ? 1 : 0]);
    }

    public function move(int $id, string $direction): void
    {
        $spot = $this->find($id);
        if ($spot === null || !in_array($direction, ['up', 'down'], true)) {
            throw new RuntimeException('並び替えるスポットが見つかりません。');
        }
        $operator = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';
        $statement = $this->database->prepare(
            "SELECT id, display_order FROM spots WHERE display_order {$operator} :display_order "
            . "ORDER BY display_order {$order}, id {$order} LIMIT 1",
        );
        $statement->execute(['display_order' => $spot['display_order']]);
        $other = $statement->fetch();
        if (!is_array($other)) {
            return;
        }

        $this->database->beginTransaction();
        try {
            $swap = $this->database->prepare('UPDATE spots SET display_order = :display_order WHERE id = :id');
            $swap->execute(['display_order' => $other['display_order'], 'id' => $id]);
            $swap->execute(['display_order' => $spot['display_order'], 'id' => $other['id']]);
            $this->database->commit();
        } catch (Throwable $error) {
            $this->database->rollBack();
            throw $error;
        }
    }

    public function reissueToken(int $id): int
    {
        $spot = $this->find($id);
        if ($spot === null) {
            throw new RuntimeException('スポットが見つかりません。');
        }
        $version = (int) $spot['token_version'] + 1;
        $statement = $this->database->prepare(
            'UPDATE spots SET token_version = :version, public_token_hash = :hash, '
            . "updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now') WHERE id = :id",
        );
        $statement->execute([
            'id' => $id,
            'version' => $version,
            'hash' => $this->tokens->hash($this->tokens->derive($id, $version)),
        ]);

        return $version;
    }

    public function delete(int $id): void
    {
        $statement = $this->database->prepare(
            'DELETE FROM spots WHERE id = :id '
            . 'AND NOT EXISTS (SELECT 1 FROM stamp_acquisitions WHERE spot_id = :id)',
        );
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('取得履歴のあるスポットは削除できません。停止してください。');
        }
    }

    public function publicToken(array $spot): string
    {
        return $this->tokens->derive((int) $spot['id'], (int) $spot['token_version']);
    }

    public function findIdByManagementToken(string $token): ?int
    {
        if (!preg_match('/^[0-9a-f-]{36}$/D', $token)) {
            return null;
        }
        $ids = $this->database->query('SELECT id FROM spots')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            if (hash_equals($this->tokens->derive((int) $id, 0), $token)) {
                return (int) $id;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $spot
     *  @return array<string, mixed>
     */
    private function withManagementToken(array $spot): array
    {
        $spot['management_token'] = $this->tokens->derive((int) $spot['id'], 0);

        return $spot;
    }

    private function setTokenHash(int $id, int $version): void
    {
        $statement = $this->database->prepare('UPDATE spots SET public_token_hash = :hash WHERE id = :id');
        $statement->execute([
            'id' => $id,
            'hash' => $this->tokens->hash($this->tokens->derive($id, $version)),
        ]);
    }
}
