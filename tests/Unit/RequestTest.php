<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QrRally\Http\Request;

final class RequestTest extends TestCase
{
    public function testRemovesConfiguredSubdirectoryFromPath(): void
    {
        $request = new Request(['REQUEST_URI' => '/qr-sr/admin/event?tab=main']);

        self::assertSame('/admin/event', $request->path('http://localhost/qr-sr/'));
    }
}
