<?php

declare(strict_types=1);

return [
    'env' => 'development',
    'base_url' => 'http://192.168.11.5/',
    'app_key' => '',
    'timezone' => 'Asia/Tokyo',
    'debug' => true,
    'log_level' => 'debug',
    'cookie_secure' => false,
    'allow_development_tools' => true,
    'database_path' => dirname(__DIR__) . '/storage/app.sqlite',
    'database_busy_timeout_ms' => 5000,
    'session_name' => 'qr_rally_admin',
    'session_lifetime_seconds' => 7200,
];
