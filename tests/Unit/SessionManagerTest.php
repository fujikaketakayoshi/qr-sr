<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use QrRally\Session\SessionManager;

final class SessionManagerTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStartsStrictCookieOnlySessionWithConfiguredLifetime(): void
    {
        (new SessionManager())->start('qr_security_test', 7200, true, '/qr-sr/');
        $parameters = session_get_cookie_params();

        self::assertSame('1', ini_get('session.use_strict_mode'));
        self::assertSame('1', ini_get('session.use_only_cookies'));
        self::assertSame('7200', ini_get('session.gc_maxlifetime'));
        self::assertSame(0, $parameters['lifetime']);
        self::assertSame('/qr-sr/', $parameters['path']);
        self::assertTrue($parameters['secure']);
        self::assertTrue($parameters['httponly']);
        self::assertSame('Lax', $parameters['samesite']);
    }
}
