<?php

declare(strict_types=1);

namespace QrRally\Security;

final class CsrfToken
{
    public function get(): string
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }

    public function verify(string $submitted): bool
    {
        return $submitted !== '' && hash_equals($this->get(), $submitted);
    }

    public function rotate(): void
    {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
}
