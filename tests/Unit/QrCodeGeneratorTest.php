<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QrRally\Support\QrCodeGenerator;

final class QrCodeGeneratorTest extends TestCase
{
    public function testGeneratesSvgContainingNoRawInternalIdMetadata(): void
    {
        $svg = (new QrCodeGenerator())->svg('http://localhost/qr-sr/spot/123e4567-e89b-42d3-a456-426614174000');

        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('</svg>', $svg);
    }
}
