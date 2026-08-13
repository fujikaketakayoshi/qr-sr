<?php

declare(strict_types=1);

namespace QrRally\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QrRally\Support\CsvValue;

final class CsvValueTest extends TestCase
{
    public function testPrefixesSpreadsheetFormulaCharacters(): void
    {
        $values = new CsvValue();
        self::assertSame("'=SUM(A1:A2)", $values->safe('=SUM(A1:A2)'));
        self::assertSame("'  @command", $values->safe('  @command'));
        self::assertSame('通常の文字列', $values->safe('通常の文字列'));
    }
}
