<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QrRally\Domain\EventStatus;

final class EventStatusTest extends TestCase
{
    /** @return iterable<string, array{bool, string, EventStatus}> */
    public static function states(): iterable
    {
        yield 'paused takes priority' => [true, '2026-08-11 12:00:00', EventStatus::Paused];
        yield 'upcoming' => [false, '2026-08-11 09:59:59', EventStatus::Upcoming];
        yield 'active at start' => [false, '2026-08-11 10:00:00', EventStatus::Active];
        yield 'active at end' => [false, '2026-08-11 18:00:00', EventStatus::Active];
        yield 'ended' => [false, '2026-08-11 18:00:01', EventStatus::Ended];
    }

    #[DataProvider('states')]
    public function testCalculatesStatus(bool $paused, string $now, EventStatus $expected): void
    {
        self::assertSame($expected, EventStatus::calculate(
            $paused,
            new DateTimeImmutable('2026-08-11 10:00:00'),
            new DateTimeImmutable('2026-08-11 18:00:00'),
            new DateTimeImmutable($now),
        ));
    }
}
