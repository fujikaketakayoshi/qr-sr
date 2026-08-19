<?php

declare(strict_types=1);

namespace QrRally\Http;

final class Request
{
    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function __construct(
        private readonly array $server,
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $cookies = [],
        private readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_SERVER, $_GET, $_POST, $_COOKIE, $_FILES);
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function path(string $baseUrl): string
    {
        $requestPath = rawurldecode((string) parse_url(
            (string) ($this->server['REQUEST_URI'] ?? '/'),
            PHP_URL_PATH,
        ));
        $basePath = (string) parse_url($baseUrl, PHP_URL_PATH);
        $basePath = '/' . trim($basePath, '/');
        if ($basePath === '/') {
            $basePath = '';
        }

        if ($basePath !== '' && str_starts_with($requestPath, $basePath)) {
            $requestPath = substr($requestPath, strlen($basePath));
        }

        return '/' . trim($requestPath, '/');
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $default;

        return is_string($value) ? trim($value) : $default;
    }

    public function boolean(string $key): bool
    {
        return isset($this->post[$key]) && in_array($this->post[$key], ['1', 'on', 'true'], true);
    }

    public function clientIp(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? 'unknown');
    }

    public function cookie(string $key): ?string
    {
        $value = $this->cookies[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @return array{name: string, tmp_name: string, error: int, size: int}|null */
    public function uploadedFile(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || !isset($file['name'], $file['tmp_name'], $file['error'], $file['size'])
            || !is_string($file['name']) || !is_string($file['tmp_name'])
            || !is_int($file['error']) || !is_int($file['size'])) {
            return null;
        }

        return ['name' => $file['name'], 'tmp_name' => $file['tmp_name'], 'error' => $file['error'], 'size' => $file['size']];
    }
}
