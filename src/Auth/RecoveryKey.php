<?php

declare(strict_types=1);

namespace QrRally\Auth;

final class RecoveryKey
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
