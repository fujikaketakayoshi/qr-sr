<?php

declare(strict_types=1);

namespace QrRally\Database;

use PDO;
use RuntimeException;

final class ConnectionFactory
{
    public function connect(string $path, int $busyTimeoutMs = 5000): PDO
    {
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('The database directory is not writable.');
        }

        $database = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $database->exec('PRAGMA foreign_keys = ON');
        $database->exec('PRAGMA journal_mode = WAL');
        $database->exec('PRAGMA synchronous = NORMAL');
        $database->exec('PRAGMA busy_timeout = ' . $busyTimeoutMs);

        if ((int) $database->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new RuntimeException('SQLite foreign key enforcement could not be enabled.');
        }

        if (strtolower((string) $database->query('PRAGMA journal_mode')->fetchColumn()) !== 'wal') {
            throw new RuntimeException('SQLite WAL mode could not be enabled.');
        }

        return $database;
    }
}
