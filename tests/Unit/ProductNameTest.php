<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductNameTest extends TestCase
{
    #[DataProvider('currentUserFacingFileProvider')]
    public function testCurrentUserFacingFilesUseTheOfficialProductName(string $path): void
    {
        $contents = file_get_contents($path);

        self::assertIsString($contents);
        self::assertStringNotContainsString('QRデジタルスタンプラリー', $contents, $path);
        self::assertDoesNotMatchRegularExpression('/QRスタンプラリー/u', $contents, $path);
    }

    public function testReadmeUsesTheOfficialDescriptionAndTrademarkNotice(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');

        self::assertIsString($readme);
        self::assertStringContainsString('# QRコードスタンプラリー', $readme);
        self::assertStringContainsString(
            'QRコードを読み取ってスタンプを集めるデジタルスタンプラリー',
            $readme,
        );
        self::assertStringContainsString('QRコードは株式会社デンソーウェーブの登録商標です。', $readme);
    }

    #[DataProvider('trademarkNoticeFileProvider')]
    public function testTrademarkNoticeIsShownInTheProduct(string $path): void
    {
        $contents = file_get_contents($path);

        self::assertIsString($contents);
        self::assertStringContainsString('QRコードは株式会社デンソーウェーブの登録商標です。', $contents, $path);
    }

    public function testReleaseNotesPreserveThePreviousNameAndDescribeTheCurrentRelease(): void
    {
        $notes = file_get_contents(dirname(__DIR__, 2) . '/RELEASE_NOTES.md');

        self::assertIsString($notes);
        self::assertStringContainsString('## 0.1.1', $notes);
        self::assertStringContainsString('製品名を「QRコードスタンプラリー」へ統一', $notes);
        self::assertStringContainsString('QRデジタルスタンプラリーの初回正式版です。', $notes);
    }

    /** @return iterable<string, array{string}> */
    public static function currentUserFacingFileProvider(): iterable
    {
        $project = dirname(__DIR__, 2);
        $files = [
            $project . '/README.md',
            $project . '/INSTALL.md',
            $project . '/LICENSE.txt',
            $project . '/THIRD_PARTY_NOTICES.md',
            $project . '/docs/ADMIN_GUIDE.md',
            $project . '/docs/PARTICIPANT_GUIDE.md',
            $project . '/docs/TROUBLESHOOTING.md',
            $project . '/docs/UNINSTALL.md',
        ];

        $templates = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($project . '/templates', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($templates as $template) {
            if ($template->isFile() && $template->getExtension() === 'php') {
                $files[] = $template->getPathname();
            }
        }

        sort($files);
        foreach ($files as $file) {
            yield str_replace($project . '/', '', $file) => [$file];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function trademarkNoticeFileProvider(): iterable
    {
        $project = dirname(__DIR__, 2);

        foreach ([
            'templates/install/layout.php',
            'templates/admin/dashboard.php',
            'templates/participant/notices.php',
        ] as $file) {
            yield $file => [$project . '/' . $file];
        }
    }
}
