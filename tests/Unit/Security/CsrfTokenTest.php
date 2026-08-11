<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use QrRally\Security\CsrfToken;

final class CsrfTokenTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testTokenIsSessionBoundAndRotatable(): void
    {
        $csrf = new CsrfToken();
        $first = $csrf->get();

        self::assertTrue($csrf->verify($first));
        self::assertFalse($csrf->verify('invalid'));

        $csrf->rotate();
        self::assertFalse($csrf->verify($first));
        self::assertTrue($csrf->verify($csrf->get()));
    }
}
