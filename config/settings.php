<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$root = dirname(__DIR__);

if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$appEnv = (string) (($_ENV['APP_ENV'] ?? getenv('APP_ENV')) ?: 'dev');
$isDev = $appEnv !== 'prod';

$corsAllowedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) (($_ENV['CORS_ALLOWED_ORIGINS'] ?? getenv('CORS_ALLOWED_ORIGINS'))
        ?: 'http://localhost:4200,http://localhost:5173')),
)));

return [
    'settings' => [
        'app_env' => $appEnv,
        'debug' => $isDev,
        'display_error_details' => $isDev,
        'log_errors' => true,
        'log_error_details' => $isDev,
        'cors_allowed_origins' => $corsAllowedOrigins,
    ],
];
