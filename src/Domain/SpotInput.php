<?php

declare(strict_types=1);

namespace QrRally\Domain;

final readonly class SpotInput
{
    public function __construct(
        public string $name,
        public string $description,
    ) {
    }
}
