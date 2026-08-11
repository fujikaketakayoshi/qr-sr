<?php

declare(strict_types=1);

namespace QrRally\Http;

use QrRally\View\TemplateRenderer;
use Throwable;

final class ErrorHandler
{
    public function __construct(
        private readonly bool $debug,
        private readonly string $logPath,
        private readonly TemplateRenderer $templates,
    ) {
    }

    public function register(): void
    {
        set_exception_handler(fn (Throwable $error) => $this->handle($error));
    }

    public function handle(Throwable $error): never
    {
        $reference = bin2hex(random_bytes(6));
        $message = sprintf(
            "[%s] reference=%s %s in %s:%d\n%s\n",
            gmdate('c'),
            $reference,
            $error::class . ': ' . $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
            $error->getTraceAsString(),
        );
        error_log($message, 3, $this->logPath);

        $body = $this->templates->render('errors/500.php', [
            'reference' => $reference,
            'debugMessage' => $this->debug ? $error->getMessage() : null,
        ]);

        (new Response($body, 500))->send();
    }
}
