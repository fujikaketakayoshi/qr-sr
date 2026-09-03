<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QrRally\Domain\ApplicationEligibility;

final class ApplicationEligibilityTest extends TestCase
{
    /** @return iterable<string, array{bool, bool, ?string, string, bool}> */
    public static function eligibilityCases(): iterable
    {
        yield '達成済み・応募有効・締切前' => [true, true, null, '2026-09-03T12:00:00Z', true];
        yield '未達成' => [false, true, null, '2026-09-03T12:00:00Z', false];
        yield '応募無効' => [true, false, null, '2026-09-03T12:00:00Z', false];
        yield '応募締切後' => [true, true, '2026-09-03T11:59:59Z', '2026-09-03T12:00:00Z', false];
        yield '応募締切ちょうど' => [true, true, '2026-09-03T12:00:00Z', '2026-09-03T12:00:00Z', true];
    }

    #[DataProvider('eligibilityCases')]
    public function testDeterminesWhetherParticipantCanApply(
        bool $completed,
        bool $enabled,
        ?string $applicationDeadline,
        string $now,
        bool $expected,
    ): void {
        $event = [
            'application_enabled' => $enabled,
            'application_deadline_at' => $applicationDeadline,
            'ends_at' => '2026-09-03T13:00:00Z',
        ];

        self::assertSame(
            $expected,
            ApplicationEligibility::canApply($event, $completed, new DateTimeImmutable($now)),
        );
    }
}
