<?php

declare(strict_types=1);

namespace QrRally\Security;

final readonly class ParticipantCookie
{
    public function __construct(
        private bool $secure,
        private string $path,
    ) {
    }

    public function header(string $token): string
    {
        return 'qr_rally_participant=' . $token
            . '; Path=' . $this->path
            . '; Max-Age=31536000; HttpOnly; SameSite=Lax'
            . ($this->secure ? '; Secure' : '');
    }
}
