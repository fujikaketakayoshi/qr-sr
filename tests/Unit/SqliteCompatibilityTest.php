<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SqliteCompatibilityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function sqlSourceFiles(): iterable
    {
        $root = dirname(__DIR__, 2);
        foreach ([$root . '/database/migrations', $root . '/src'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
            foreach ($files as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'sql'], true)) {
                    $path = $file->getPathname();
                    yield substr($path, strlen($root) + 1) => [$path];
                }
            }
        }
    }

    #[DataProvider('sqlSourceFiles')]
    public function testDoesNotUseKnownPost326SchemaSyntax(string $path): void
    {
        $source = (string) file_get_contents($path);

        self::assertDoesNotMatchRegularExpression('/\bSTRICT\b/i', $source);
        self::assertStringNotContainsString('sqlite_schema', strtolower($source));
    }
}
