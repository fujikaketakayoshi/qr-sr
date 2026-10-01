<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use QrRally\Support\DemoMode;

final class DemoModeTest extends TestCase
{
    public function testReportsWhetherDemoModeIsEnabled(): void
    {
        self::assertTrue((new DemoMode(true, 'Asia/Tokyo'))->enabled());
        self::assertFalse((new DemoMode(false, 'Asia/Tokyo'))->enabled());
    }

    public function testCalculatesNextResetAtTopOfNextHour(): void
    {
        $demo = new DemoMode(true, 'Asia/Tokyo');

        self::assertSame(
            '2026年10月1日 22:00',
            $demo->nextResetAt(new DateTimeImmutable('2026-10-01 12:34:56+00:00')),
        );
    }
}
