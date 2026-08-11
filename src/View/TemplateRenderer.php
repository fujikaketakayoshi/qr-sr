<?php

declare(strict_types=1);

namespace QrRally\View;

use RuntimeException;

final class TemplateRenderer
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $path = $this->directory . '/' . ltrim($template, '/');
        $realDirectory = realpath($this->directory);
        $realPath = realpath($path);

        if ($realDirectory === false || $realPath === false
            || !str_starts_with($realPath, $realDirectory . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Template not found.');
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $realPath;

        return (string) ob_get_clean();
    }
}
