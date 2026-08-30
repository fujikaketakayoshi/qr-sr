<?php

declare(strict_types=1);

namespace QrRally\Install;

final class InstallInput
{
    public function __construct(
        public readonly string $environment,
        public readonly string $baseUrl,
        public readonly string $privatePath,
        public readonly string $timezone,
        public readonly string $email = '',
        public readonly string $password = '',
        public readonly string $passwordConfirmation = '',
    ) {
    }
}
