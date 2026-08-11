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
}
