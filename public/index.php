<?php

declare(strict_types=1);

use QrRally\Http\ErrorHandler;
use QrRally\Http\Application;
use QrRally\Http\Request;
use QrRally\Http\Response;
use QrRally\Auth\AdminAuth;
use QrRally\Auth\CredentialUpdater;
use QrRally\Auth\PasswordPolicy;
use QrRally\Auth\PasswordResetter;
use QrRally\Auth\RecoveryKey;
use QrRally\Controller\AdminController;
use QrRally\Controller\SpotController;
use QrRally\Domain\EventValidator;
use QrRally\Repository\AdminRepository;
use QrRally\Repository\AuditLogRepository;
use QrRally\Repository\EventRepository;
use QrRally\Repository\LoginAttemptRepository;
use QrRally\Repository\SpotRepository;
use QrRally\Security\CsrfToken;
use QrRally\Security\SpotToken;
use QrRally\Session\Flash;
use QrRally\Session\SessionManager;
use QrRally\Support\UrlGenerator;
use QrRally\Support\ViewData;
use QrRally\Support\QrCodeGenerator;
use QrRally\Support\DownloadFilename;
use QrRally\Domain\SpotValidator;
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

$sessions = new SessionManager();
$sessions->start(
    $config->string('session_name'),
    $config->int('session_lifetime_seconds'),
    $config->bool('cookie_secure'),
    (string) (parse_url($config->string('base_url'), PHP_URL_PATH) ?: '/'),
);
$csrf = new CsrfToken();
$flash = new Flash();
$urls = new UrlGenerator($config->string('base_url'));
$database = $container['database'];
$admins = new AdminRepository($database);
$logs = new AuditLogRepository($database);
$auth = new AdminAuth(
    $admins,
    new LoginAttemptRepository($database),
    $logs,
    $sessions,
    $csrf,
    $config->string('app_key'),
    $config->int('session_lifetime_seconds'),
);
$spotRepository = new SpotRepository($database, new SpotToken($config->string('app_key')));
$controller = new AdminController(
    $templates,
    new ViewData($urls, $csrf, $flash),
    $urls,
    $csrf,
    $flash,
    $auth,
    new PasswordResetter(
        $admins,
        $logs,
        new PasswordPolicy(),
        new CredentialUpdater($database, $admins, $logs, new RecoveryKey()),
    ),
    new EventRepository($database),
    $spotRepository,
    new EventValidator(),
    $logs,
    $config->string('timezone'),
);
$spotController = new SpotController(
    $templates,
    new ViewData($urls, $csrf, $flash),
    $urls,
    $csrf,
    $flash,
    $auth,
    $spotRepository,
    new EventRepository($database),
    $logs,
    new SpotValidator(),
    new QrCodeGenerator(),
    new DownloadFilename(),
);
$request = Request::fromGlobals();

if ($request->method() === 'GET' && $request->path($config->string('base_url')) === '/') {
    $count = (int) $database->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $body = $templates->render('home.php', [
        'environment' => $config->string('env'),
        'migrationCount' => $count,
        'assetUrl' => $urls->to('assets/app.css'),
        'adminUrl' => $urls->to('admin/'),
    ]);
    (new Response($body))->send();
}

(new Application($controller, $spotController, $templates, $config->string('base_url')))
    ->handle($request)
    ->send();
