<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use QrRally\Domain\SpotInput;
use QrRally\Domain\SpotValidator;

final class SpotValidatorTest extends TestCase
{
    public function testAcceptsFiftyCharacterNameAndRejectsFiftyOneCharacters(): void
    {
        $validator = new SpotValidator();

        self::assertArrayNotHasKey('name', $validator->validate(new SpotInput(str_repeat('あ', 50), '')));
        self::assertSame(
            'スポット名は1〜50文字で入力してください。',
            $validator->validate(new SpotInput(str_repeat('あ', 51), ''))['name'],
        );
    }
}
