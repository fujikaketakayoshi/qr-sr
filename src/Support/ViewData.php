<?php

declare(strict_types=1);

namespace QrRally\Support;

use QrRally\Security\CsrfToken;
use QrRally\Session\Flash;

final class ViewData
{
    public function __construct(
        private readonly UrlGenerator $urls,
        private readonly CsrfToken $csrf,
        private readonly Flash $flash,
    ) {
    }

    /** @return array<string, mixed> */
    public function common(): array
    {
        return [
            'url' => fn (string $path = ''): string => $this->urls->to($path),
            'csrfToken' => $this->csrf->get(),
            'flash' => $this->flash->pull(),
        ];
    }
}
