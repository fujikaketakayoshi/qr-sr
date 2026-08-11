<?php

declare(strict_types=1);

use QrRally\Http\ErrorHandler;
use QrRally\Http\Response;
use QrRally\View\TemplateRenderer;

require dirname(__DIR__) . '/vendor/autoload.php';

$templates = new TemplateRenderer(dirname(__DIR__) . '/templates');

try {
    $container = require dirname(__DIR__) . '/bootstrap.php';
    $config = $container['config'];
} catch (Throwable $error) {
    (new ErrorHandler(false, dirname(__DIR__) . '/storage/logs/app.log', $templates))
        ->handle($error);
}

(new ErrorHandler(
    $config->bool('debug'),
    dirname(__DIR__) . '/storage/logs/app.log',
    $templates,
))->register();

$count = (int) $container['database']
    ->query('SELECT COUNT(*) FROM schema_migrations')
    ->fetchColumn();

$body = $templates->render('home.php', [
    'environment' => $config->string('env'),
    'migrationCount' => $count,
    'assetUrl' => $config->string('base_url') . 'assets/app.css',
]);

(new Response($body))->send();
