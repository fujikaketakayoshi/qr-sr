<?php

declare(strict_types=1);

namespace QrRally\Support;

final class ConsoleInput
{
    public function line(string $prompt): string
    {
        fwrite(STDOUT, $prompt);

        return trim((string) fgets(STDIN));
    }

    public function hidden(string $prompt): string
    {
        fwrite(STDOUT, $prompt);
        $echoDisabled = false;
        if (function_exists('shell_exec') && $this->isInteractive()) {
            shell_exec('stty -echo 2>/dev/null');
            $echoDisabled = true;
        }

        try {
            return rtrim((string) fgets(STDIN), "\r\n");
        } finally {
            if ($echoDisabled) {
                shell_exec('stty echo 2>/dev/null');
                fwrite(STDOUT, "\n");
            }
        }
    }

    private function isInteractive(): bool
    {
        return function_exists('stream_isatty') && stream_isatty(STDIN);
    }
}
