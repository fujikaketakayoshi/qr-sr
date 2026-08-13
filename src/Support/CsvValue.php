<?php

declare(strict_types=1);

namespace QrRally\Support;

final class CsvValue
{
    public function safe(string $value): string
    {
        return preg_match('/^[=+\-@]/u', ltrim($value)) ? "'" . $value : $value;
    }
}
