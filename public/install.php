<?php

declare(strict_types=1);

use QrRally\Config\Config;
use QrRally\Controller\InstallController;
use QrRally\Http\Request;
use QrRally\Http\Response;
use QrRally\Install\EnvironmentInspector;
use QrRally\Install\Installer;
use QrRally\Security\CsrfToken;
use QrRally\Session\SessionManager;
use QrRally\View\TemplateRenderer;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$templates = new TemplateRenderer($root . '/templates');
$inspector = new EnvironmentInspector($root);
$assetBase = (string) parse_url($inspector->candidateBaseUrl($_SERVER), PHP_URL_PATH);
$assetBase = '/' . trim($assetBase, '/') . ($assetBase === '/' ? '' : '/');
if ($assetBase === '//') {
    $assetBase = '/';
}
$publicConfig = $root . '/config/app.php';

if (is_file($publicConfig)) {
    try {
        $config = Config::load($publicConfig);
        Response::redirect($config->string('base_url') . 'admin/login')->send();
    } catch (Throwable) {
        (new Response($templates->renderWithLayout('install/broken.php', [
            'title' => '設定エラー',
            'assetBase' => $assetBase,
        ], 'install/layout.php'), 500, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]))->send();
    }
}

(new SessionManager())->start('qr_rally_install', 1800, $inspector->isHttps($_SERVER), $assetBase);
$controller = new InstallController(
    $templates,
    new CsrfToken(),
    $inspector,
    new Installer($root, $publicConfig, $inspector),
    $assetBase,
);
$controller->handle(Request::fromGlobals(), $_SERVER)->send();
