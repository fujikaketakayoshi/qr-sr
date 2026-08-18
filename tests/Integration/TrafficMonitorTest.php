<?php

declare(strict_types=1);

namespace QrRally\Tests\Integration;

use PHPUnit\Framework\TestCase;
use QrRally\Security\TrafficMonitor;

final class TrafficMonitorTest extends TestCase
{
    private string $directory;
    private string $path;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qr-traffic-' . bin2hex(random_bytes(6));
        $this->path = $this->directory . '/runtime/traffic.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/runtime/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory . '/runtime');
        @rmdir($this->directory);
    }

    public function testAllowsSixHundredRequestsAndRejectsNextWithinSixtySeconds(): void
    {
        $monitor = $this->monitor();
        for ($request = 1; $request <= 600; $request++) {
            self::assertTrue($monitor->allowRequest('203.0.113.10'));
        }
        self::assertFalse($monitor->allowRequest('203.0.113.10'));
        self::assertTrue($monitor->allowRequest('203.0.113.11'));

        $this->now += 60;
        self::assertTrue($monitor->allowRequest('203.0.113.10'));
        self::assertStringNotContainsString('203.0.113.10', (string) file_get_contents($this->path));
    }

    public function testWarnsAtTenDistinctSpotsWithoutBlockingAndSuppressesForFiveMinutes(): void
    {
        $monitor = $this->monitor();
        for ($spot = 1; $spot < 10; $spot++) {
            self::assertNull($monitor->recordSpotAccess(42, $spot, '198.51.100.20'));
        }
        $warning = $monitor->recordSpotAccess(42, 10, '198.51.100.20');

        self::assertSame(10, $warning['distinct_spot_count']);
        self::assertSame($monitor->hashIp('198.51.100.20'), $warning['ip_hash']);
        self::assertNull($monitor->recordSpotAccess(42, 11, '198.51.100.20'));

        $this->now += 301;
        for ($spot = 20; $spot < 29; $spot++) {
            self::assertNull($monitor->recordSpotAccess(42, $spot, '198.51.100.20'));
        }
        self::assertNotNull($monitor->recordSpotAccess(42, 29, '198.51.100.20'));
    }

    private function monitor(): TrafficMonitor
    {
        return new TrafficMonitor(
            $this->path,
            str_repeat('a', 64),
            clock: fn (): int => $this->now,
        );
    }
}
