<?php

declare(strict_types=1);

namespace QrRally\Support;

final class UrlGenerator
{
    public function __construct(private readonly string $baseUrl)
    {
    }

    public function to(string $path = ''): string
    {
        return $this->baseUrl . ltrim($path, '/');
    }
}
