<?php

declare(strict_types=1);

return [
    'env' => 'development',
    'base_url' => 'http://192.168.11.5/',
    'app_key' => '',
    'spot_token_key' => '',
    'timezone' => 'Asia/Tokyo',
    'debug' => true,
    'log_level' => 'debug',
    'cookie_secure' => false,
    'allow_development_tools' => true,
    // 公開デモ環境でのみ手作業で true にします。通常の設置では false のままにしてください。
    'demo_mode' => false,
    'database_path' => dirname(__DIR__) . '/storage/app.sqlite',
    'database_busy_timeout_ms' => 5000,
    'session_name' => 'qr_rally_admin',
    'session_lifetime_seconds' => 7200,
];
