<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use QrRally\Security\ParticipantCookie;

final class ParticipantCookieTest extends TestCase
{
    public function testProductionCookieHasRequiredAttributes(): void
    {
        $header = (new ParticipantCookie(true, '/qr-sr/'))->header('participant-token');

        self::assertSame(
            'qr_rally_participant=participant-token; Path=/qr-sr/; Max-Age=31536000; HttpOnly; SameSite=Lax; Secure',
            $header,
        );
    }

    public function testLocalHttpCookieOmitsOnlySecureAttribute(): void
    {
        $header = (new ParticipantCookie(false, '/'))->header('participant-token');

        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Lax', $header);
        self::assertStringContainsString('Max-Age=31536000', $header);
        self::assertStringNotContainsString('; Secure', $header);
    }
}
