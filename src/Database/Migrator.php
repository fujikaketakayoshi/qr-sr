<?php

declare(strict_types=1);

namespace QrRally\Database;

use PDO;
use RuntimeException;
use Throwable;

final class Migrator
{
    public function __construct(
        private readonly PDO $database,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'migration TEXT PRIMARY KEY, applied_at TEXT NOT NULL) STRICT',
        );

        $files = glob($this->directory . '/*.sql');
        if ($files === false) {
            throw new RuntimeException('Unable to read the migrations directory.');
        }

        sort($files, SORT_STRING);
        $applied = [];
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration',
        );

        foreach ($files as $file) {
            $name = basename($file);
            $statement->execute(['migration' => $name]);
            if ((int) $statement->fetchColumn() > 0) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException("Migration is empty or unreadable: {$name}");
            }

            $this->database->beginTransaction();
            try {
                $this->database->exec($sql);
                $insert = $this->database->prepare(
                    'INSERT INTO schema_migrations (migration, applied_at) '
                    . "VALUES (:migration, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))",
                );
                $insert->execute(['migration' => $name]);
                $this->database->commit();
                $applied[] = $name;
            } catch (Throwable $error) {
                if ($this->database->inTransaction()) {
                    $this->database->rollBack();
                }

                throw new RuntimeException("Migration failed: {$name}", 0, $error);
            }
        }

        return $applied;
    }
}
