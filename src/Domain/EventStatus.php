<?php

declare(strict_types=1);

namespace QrRally\Domain;

use DateTimeImmutable;

enum EventStatus: string
{
    case Paused = 'paused';
    case Upcoming = 'upcoming';
    case Active = 'active';
    case Ended = 'ended';

    public static function calculate(
        bool $isPaused,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        DateTimeImmutable $now,
    ): self {
        if ($isPaused) {
            return self::Paused;
        }
        if ($now < $startsAt) {
            return self::Upcoming;
        }
        if ($now > $endsAt) {
            return self::Ended;
        }

        return self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Paused => '一時停止',
            self::Upcoming => '開催前',
            self::Active => '開催中',
            self::Ended => '終了',
        };
    }
}
