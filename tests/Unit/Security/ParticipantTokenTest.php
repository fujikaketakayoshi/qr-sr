<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use QrRally\Security\ParticipantToken;

final class ParticipantTokenTest extends TestCase
{
    public function testGeneratesValidUniqueUuidV4Tokens(): void
    {
        $tokens = new ParticipantToken();
        $first = $tokens->generate();
        $second = $tokens->generate();

        self::assertTrue($tokens->isValid($first));
        self::assertNotSame($first, $second);
        self::assertSame(hash('sha256', $first), $tokens->hash($first));
        self::assertFalse($tokens->isValid('not-a-token'));
    }
}
