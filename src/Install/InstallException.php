<?php

declare(strict_types=1);

namespace QrRally\Install;

use RuntimeException;
use Throwable;

final class InstallException extends RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(string $message, private readonly array $errors = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
