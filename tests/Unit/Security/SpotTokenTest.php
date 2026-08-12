<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use QrRally\Security\SpotToken;

final class SpotTokenTest extends TestCase
{
    public function testDerivesStableUuidV4AndChangesByVersion(): void
    {
        $tokens = new SpotToken(str_repeat('a', 64));
        $first = $tokens->derive(10, 1);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $first,
        );
        self::assertSame($first, $tokens->derive(10, 1));
        self::assertNotSame($first, $tokens->derive(10, 2));
        self::assertNotSame($first, $tokens->derive(11, 1));
    }
}
