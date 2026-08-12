<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QrRally\Support\DownloadFilename;

final class DownloadFilenameTest extends TestCase
{
    public function testUsesDisplayOrderAndJapaneseSpotName(): void
    {
        self::assertSame(
            '02-中央図書館-qr.svg',
            (new DownloadFilename())->spotQrSvg(2, '中央図書館'),
        );
    }

    public function testReplacesCharactersThatAreUnsafeInFilenames(): void
    {
        self::assertSame(
            '03-本館-受付-qr.svg',
            (new DownloadFilename())->spotQrSvg(3, '本館/受付'),
        );
    }
}
