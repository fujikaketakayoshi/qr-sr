<?php

declare(strict_types=1);

namespace QrRally\Domain;

final readonly class ApplicationInput
{
    /** @param array<string, string> $values */
    public function __construct(public array $values, public bool $privacyAccepted)
    {
    }
}
