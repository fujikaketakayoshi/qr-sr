<?php

declare(strict_types=1);

namespace QrRally\Database;

use Closure;
use PDOException;
use QrRally\Exception\DatabaseBusyException;

final class SqliteWriteRetrier
{
    private Closure $sleeper;
    private ?Closure $onRetry;

    /** @param list<int> $delaysMs */
    public function __construct(
        private readonly array $delaysMs = [100, 250],
        ?Closure $sleeper = null,
        ?Closure $onRetry = null,
    ) {
        $this->sleeper = $sleeper ?? static fn (int $milliseconds): int => usleep($milliseconds * 1000);
        $this->onRetry = $onRetry;
    }

    /** @template T @param Closure():T $operation @return T */
    public function run(Closure $operation): mixed
    {
        foreach ([null, ...$this->delaysMs] as $attempt => $delayMs) {
            if ($delayMs !== null) {
                ($this->sleeper)($delayMs);
                if ($this->onRetry !== null) {
                    ($this->onRetry)($attempt, $delayMs);
                }
            }

            try {
                return $operation();
            } catch (PDOException $error) {
                if (!$this->isLocked($error)) {
                    throw $error;
                }
                if ($attempt === count($this->delaysMs)) {
                    throw new DatabaseBusyException('SQLite remained locked after limited retries.', 0, $error);
                }
            }
        }

        throw new DatabaseBusyException('SQLite write retry failed unexpectedly.');
    }

    private function isLocked(PDOException $error): bool
    {
        $driverCode = $error->errorInfo[1] ?? null;
        return in_array($driverCode, [5, 6], true)
            || str_contains(mb_strtolower($error->getMessage()), 'database is locked')
            || str_contains(mb_strtolower($error->getMessage()), 'database table is locked');
    }
}
