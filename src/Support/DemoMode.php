<?php

declare(strict_types=1);

namespace QrRally\Support;

use DateTimeImmutable;
use DateTimeZone;

final class DemoMode
{
    private readonly DateTimeZone $timezone;

    public function __construct(
        private readonly bool $enabled,
        string $timezone,
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function nextResetAt(?DateTimeImmutable $now = null): string
    {
        $local = ($now ?? new DateTimeImmutable('now'))->setTimezone($this->timezone);
        $next = $local->setTime((int) $local->format('H'), 0)->modify('+1 hour');

        return $next->format('Y年n月j日 H:i');
    }
}
