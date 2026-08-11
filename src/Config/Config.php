<?php

declare(strict_types=1);

namespace QrRally\Config;

use InvalidArgumentException;
use RuntimeException;

final class Config
{
    private const ENVIRONMENTS = ['development', 'production'];

    /** @param array<string, bool|int|string> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function load(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(
                'Configuration is missing. Run: php bin/setup-local.php',
            );
        }

        $values = require $path;
        if (!is_array($values)) {
            throw new RuntimeException('Configuration must return an array.');
        }

        $overrides = [
            'env' => self::environment('APP_ENV'),
            'base_url' => self::environment('APP_BASE_URL'),
            'database_path' => self::environment('APP_DATABASE_PATH'),
        ];

        foreach ($overrides as $key => $value) {
            if ($value !== null && $value !== '') {
                $values[$key] = $value;
            }
        }

        self::validate($values);

        return new self($values);
    }

    public function string(string $key): string
    {
        $value = $this->values[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException("Configuration value '{$key}' is not a string.");
        }

        return $value;
    }

    public function int(string $key): int
    {
        $value = $this->values[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException("Configuration value '{$key}' is not an integer.");
        }

        return $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->values[$key] ?? null;
        if (!is_bool($value)) {
            throw new InvalidArgumentException("Configuration value '{$key}' is not a boolean.");
        }

        return $value;
    }

    public function isProduction(): bool
    {
        return $this->string('env') === 'production';
    }

    private static function environment(string $name): ?string
    {
        $value = getenv($name);

        return $value === false ? null : $value;
    }

    /** @param array<string, mixed> $values */
    private static function validate(array $values): void
    {
        $required = [
            'env' => 'string',
            'base_url' => 'string',
            'debug' => 'boolean',
            'log_level' => 'string',
            'cookie_secure' => 'boolean',
            'allow_development_tools' => 'boolean',
            'database_path' => 'string',
            'database_busy_timeout_ms' => 'integer',
        ];

        foreach ($required as $key => $type) {
            if (!array_key_exists($key, $values) || gettype($values[$key]) !== $type) {
                throw new InvalidArgumentException("Invalid configuration value: {$key}");
            }
        }

        if (!in_array($values['env'], self::ENVIRONMENTS, true)) {
            throw new InvalidArgumentException('APP_ENV must be development or production.');
        }

        if (filter_var($values['base_url'], FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('APP_BASE_URL must be an absolute URL.');
        }

        if (!str_ends_with($values['base_url'], '/')) {
            throw new InvalidArgumentException('APP_BASE_URL must end with a slash.');
        }

        if ($values['database_busy_timeout_ms'] < 1 || $values['database_busy_timeout_ms'] > 30000) {
            throw new InvalidArgumentException('database_busy_timeout_ms must be between 1 and 30000.');
        }

        if ($values['env'] === 'production') {
            if ($values['debug'] || $values['allow_development_tools']) {
                throw new InvalidArgumentException('Production must disable debug and development tools.');
            }

            if (!str_starts_with($values['base_url'], 'https://') || !$values['cookie_secure']) {
                throw new InvalidArgumentException('Production requires HTTPS and secure cookies.');
            }
        }
    }
}
