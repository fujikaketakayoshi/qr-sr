<?php

declare(strict_types=1);

namespace QrRally\Security;

final class SpotToken
{
    public function __construct(private readonly string $appKey)
    {
    }

    public function derive(int $spotId, int $version): string
    {
        $bytes = substr(hash_hmac('sha256', "spot:{$spotId}:{$version}", $this->appKey, true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
