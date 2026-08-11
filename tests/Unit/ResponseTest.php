<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QrRally\Http\Response;

final class ResponseTest extends TestCase
{
    public function testExposesBodyAndStatus(): void
    {
        $response = new Response('hello', 202);

        self::assertSame('hello', $response->body());
        self::assertSame(202, $response->status());
    }
}
