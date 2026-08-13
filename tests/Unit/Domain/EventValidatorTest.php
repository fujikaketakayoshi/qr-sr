<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use QrRally\Domain\EventInput;
use QrRally\Domain\EventValidator;

final class EventValidatorTest extends TestCase
{
    public function testAcceptsValidEventAndConvertsToUtc(): void
    {
        $validator = new EventValidator();
        $input = new EventInput('夏祭り', '', '', '2026-08-11T10:00', '2026-08-11T18:00', false, '', 5, '達成');

        self::assertSame([], $validator->validate($input, 'Asia/Tokyo'));
        self::assertSame('2026-08-11T01:00:00Z', $validator->toUtc($input->startsAt, 'Asia/Tokyo'));
    }

    public function testRejectsInvalidPeriodAndPausedWithoutMessage(): void
    {
        $validator = new EventValidator();
        $input = new EventInput('', '', '', '2026-08-11T18:00', '2026-08-11T10:00', true, '', 0, '');

        $errors = $validator->validate($input, 'Asia/Tokyo');

        self::assertArrayHasKey('name', $errors);
        self::assertArrayHasKey('ends_at', $errors);
        self::assertArrayHasKey('required_stamp_count', $errors);
        self::assertArrayHasKey('pause_message', $errors);
    }

    public function testRejectsRequiredCountAboveRegisteredSpots(): void
    {
        $input = new EventInput('イベント', '', '', '2026-08-11T10:00', '2026-08-11T18:00', false, '', 5, '');

        $errors = (new EventValidator())->validate($input, 'Asia/Tokyo', 3);

        self::assertArrayHasKey('required_stamp_count', $errors);
    }

    public function testAcceptsEndAtOrBeforeExplicitApplicationDeadline(): void
    {
        $validator = new EventValidator();
        $before = new EventInput('イベント', '', '', '2026-08-11T10:00', '2026-08-20T17:59', false, '', 1, '');
        $same = new EventInput('イベント', '', '', '2026-08-11T10:00', '2026-08-20T18:00', false, '', 1, '');

        self::assertSame([], $validator->validate($before, 'Asia/Tokyo', null, '2026-08-20T09:00:00Z'));
        self::assertSame([], $validator->validate($same, 'Asia/Tokyo', null, '2026-08-20T09:00:00Z'));
    }

    public function testRejectsEndAfterExplicitApplicationDeadline(): void
    {
        $input = new EventInput('イベント', '', '', '2026-08-11T10:00', '2026-08-20T18:01', false, '', 1, '');

        $errors = (new EventValidator())->validate($input, 'Asia/Tokyo', null, '2026-08-20T09:00:00Z');

        self::assertSame(
            '終了日時は、設定済みの応募締切（2026年8月20日 18:00）以前にしてください。先に応募設定で締切を変更することもできます。',
            $errors['ends_at'],
        );
    }

    public function testAllowsEndChangeWhenApplicationDeadlineIsNotExplicit(): void
    {
        $input = new EventInput('イベント', '', '', '2026-08-11T10:00', '2026-09-01T18:00', false, '', 1, '');

        self::assertSame([], (new EventValidator())->validate($input, 'Asia/Tokyo', null, null));
    }
}
