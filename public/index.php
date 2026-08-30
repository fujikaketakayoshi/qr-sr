<?php

declare(strict_types=1);

use QrRally\Http\ErrorHandler;
use QrRally\Config\Config;
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
use QrRally\Controller\ParticipantController;
use QrRally\Controller\ApplicationController;
use QrRally\Controller\PrintController;
use QrRally\Controller\BackupController;
use QrRally\Backup\BackupManager;
use QrRally\Backup\RestoreStateStore;
use QrRally\Domain\ApplicationValidator;
use QrRally\Repository\ApplicationRepository;
use QrRally\Domain\EventValidator;
use QrRally\Repository\AdminRepository;
use QrRally\Repository\AuditLogRepository;
use QrRally\Repository\EventRepository;
use QrRally\Repository\LoginAttemptRepository;
use QrRally\Repository\SpotRepository;
use QrRally\Repository\ParticipantRepository;
use QrRally\Repository\BackupOperationRepository;
use QrRally\Security\CsrfToken;
use QrRally\Security\SpotToken;
use QrRally\Security\ParticipantToken;
use QrRally\Security\ParticipantCookie;
use QrRally\Security\TrafficMonitor;
use QrRally\Session\Flash;
use QrRally\Session\SessionManager;
use QrRally\Support\UrlGenerator;
use QrRally\Support\ViewData;
use QrRally\Support\QrCodeGenerator;
use QrRally\Support\DownloadFilename;
use QrRally\Support\CsvValue;
use QrRally\Support\PrintPdfGenerator;
use QrRally\Domain\SpotValidator;
use QrRally\View\TemplateRenderer;

require dirname(__DIR__) . '/vendor/autoload.php';

$templates = new TemplateRenderer(dirname(__DIR__) . '/templates');
$publicConfigPath = dirname(__DIR__) . '/config/app.php';
if (!is_file($publicConfigPath)) {
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/public/index.php'));
    $basePath = preg_replace('#/(?:public/)?index\.php$#', '', $script) ?? '';
    $installUrl = '/' . trim($basePath, '/') . ($basePath === '' ? '' : '/') . 'install';
    header('Location: ' . $installUrl, true, 302);
    exit;
}

try {
    $config = Config::load($publicConfigPath);
} catch (Throwable $error) {
    $reference = bin2hex(random_bytes(6));
    error_log(sprintf('[qr-rally] reference=%s config error: %s', $reference, $error->getMessage()));
    (new Response($templates->render('errors/500.php', [
        'reference' => $reference,
        'debugMessage' => null,
    ]), 500))->send();
}

(new ErrorHandler(
    $config->bool('debug'),
    dirname($config->string('database_path')) . '/logs/app.log',
    $templates,
))->register();
$container = require dirname(__DIR__) . '/bootstrap.php';
$config = $container['config'];

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
$traffic = new TrafficMonitor(
    dirname($config->string('database_path')) . '/runtime/traffic.sqlite',
    $config->string('app_key'),
);
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
$spotRepository = new SpotRepository($database, new SpotToken($config->string('spot_token_key')));
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
    new ApplicationRepository($database),
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
$participantToken = new ParticipantToken();
$participantController = new ParticipantController(
    $templates,
    new ViewData($urls, $csrf, $flash),
    $urls,
    $csrf,
    new EventRepository($database),
    $spotRepository,
    new ParticipantRepository($database, $participantToken),
    $participantToken,
    $logs,
    $config->string('app_key'),
    new ParticipantCookie(
        $config->bool('cookie_secure'),
        (string) (parse_url($config->string('base_url'), PHP_URL_PATH) ?: '/'),
    ),
    $traffic,
);
$applicationController = new ApplicationController(
    $templates, new ViewData($urls, $csrf, $flash), $urls, $csrf, $flash, $auth,
    new EventRepository($database), new ParticipantRepository($database, $participantToken),
    new ApplicationRepository($database), new ApplicationValidator(), $participantToken, $logs, new CsvValue(),
    $config->string('timezone'),
);
$printController = new PrintController(
    $templates,
    new ViewData($urls, $csrf, $flash),
    $urls,
    $auth,
    new EventRepository($database),
    $spotRepository,
    $logs,
    new QrCodeGenerator(),
    new PrintPdfGenerator(dirname(__DIR__)),
    dirname(__DIR__) . '/public/assets/fonts',
    $config->string('timezone'),
);
$privateStorage = dirname($config->string('database_path'));
$restoreStates = new RestoreStateStore($privateStorage . '/backups/restore-state.json');
$backupManager = new BackupManager(
    $config->string('database_path'),
    $privateStorage . '/backups',
    $config->string('base_url'),
    $config->int('database_busy_timeout_ms'),
    null,
    $config->string('spot_token_key'),
);
$backupController = new BackupController(
    $templates,
    new ViewData($urls, $csrf, $flash),
    $urls,
    $csrf,
    $flash,
    $auth,
    $admins,
    new EventRepository($database),
    new ApplicationRepository($database),
    new BackupOperationRepository($database),
    $logs,
    $backupManager,
    $restoreStates,
    $config->string('base_url'),
);
$request = Request::fromGlobals();

(new Application($controller, $spotController, $participantController, $applicationController, $printController, $backupController, $templates, $config->string('base_url'), $traffic, $restoreStates))
    ->handle($request)
    ->send();
