<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use PDOException;
use PHPUnit\Framework\TestCase;
use QrRally\Database\SqliteWriteRetrier;
use QrRally\Exception\DatabaseBusyException;

final class SqliteWriteRetrierTest extends TestCase
{
    public function testRetriesLockedWriteUsingConfiguredDelays(): void
    {
        $attempts = 0;
        $slept = [];
        $retrier = new SqliteWriteRetrier([100, 250], static function (int $milliseconds) use (&$slept): void {
            $slept[] = $milliseconds;
        });

        $result = $retrier->run(static function () use (&$attempts): string {
            $attempts++;
            if ($attempts < 3) {
                throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
            }
            return 'saved';
        });

        self::assertSame('saved', $result);
        self::assertSame(3, $attempts);
        self::assertSame([100, 250], $slept);
    }

    public function testStopsAfterTwoRetries(): void
    {
        $attempts = 0;
        $retrier = new SqliteWriteRetrier([100, 250], static fn (int $milliseconds): null => null);

        try {
            $retrier->run(static function () use (&$attempts): never {
                $attempts++;
                throw new PDOException('database is locked');
            });
            self::fail('DatabaseBusyException was not thrown.');
        } catch (DatabaseBusyException) {
            self::assertSame(3, $attempts);
        }
    }

    public function testDoesNotRetryUniqueConstraintViolation(): void
    {
        $attempts = 0;
        $error = new PDOException('UNIQUE constraint failed');
        $error->errorInfo = ['23000', 19, 'UNIQUE constraint failed'];

        try {
            (new SqliteWriteRetrier([100, 250], static fn (int $milliseconds): null => null))
                ->run(static function () use (&$attempts, $error): never {
                    $attempts++;
                    throw $error;
                });
            self::fail('PDOException was not thrown.');
        } catch (PDOException) {
            self::assertSame(1, $attempts);
        }
    }
}
