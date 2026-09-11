<?php

declare(strict_types=1);

namespace QrRally\Domain;

use DateTimeImmutable;
use DateTimeZone;

final class ApplicationEligibility
{
    /** @param array<string, mixed> $event */
    public static function canApply(array $event, bool $completed, ?DateTimeImmutable $now = null): bool
    {
        return $completed
            && (bool) $event['application_enabled']
            && !self::deadlinePassed($event, $now);
    }

    /** @param array<string, mixed> $event */
    public static function deadlinePassed(array $event, ?DateTimeImmutable $now = null): bool
    {
        $deadline = $event['application_deadline_at'] ?: $event['ends_at'];
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $now > new DateTimeImmutable((string) $deadline);
    }

    /** @param array<string, mixed> $event */
    public static function editUnavailableMessage(array $event, ?DateTimeImmutable $now = null): string
    {
        if (self::deadlinePassed($event, $now)) {
            return '応募締切を過ぎているため、内容は変更できません。';
        }
        if (!(bool) $event['application_enabled']) {
            return '現在、応募受付は停止されているため、内容は変更できません。';
        }

        return '現在、応募内容は変更できません。';
    }
}
