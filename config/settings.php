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
    static fn(string $origin): string => trim($origin),
    explode(',', (string) (($_ENV['CORS_ALLOWED_ORIGINS'] ?? getenv('CORS_ALLOWED_ORIGINS'))
        ?: 'http://localhost:4200,http://localhost:5173')),
)));

$env = static fn(string $key, string $default = ''): string => (string) (($_ENV[$key] ?? getenv($key)) ?: $default);

// APP_DEBUG переопределяет поведение по умолчанию (dev => on, prod => off).
$debug = filter_var($env('APP_DEBUG', $isDev ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);

return [
    'settings' => [
        'app_env' => $appEnv,
        'debug' => $debug,
        'display_error_details' => $debug,
        'log_errors' => true,
        'log_error_details' => $debug,
        'cors_allowed_origins' => $corsAllowedOrigins,
        // Импорт товаров: каталог для загруженных файлов и лимит размера (10 МБ).
        'import_upload_dir' => $root . '/var/uploads/imports',
        'import_max_file_size' => 10 * 1024 * 1024,
        // Статусы задач импорта: var/imports/{jobId}.json (пишет воркер, читает API).
        'import_status_dir' => $root . '/var/imports',
        // Rate limit POST /api/import: счётчики по IP в var/rate_limit.
        'rate_limit_dir' => $root . '/var/rate_limit',
        'rate_limit_max' => (int) $env('RATE_LIMIT_MAX', '10'),
        'rate_limit_window' => (int) $env('RATE_LIMIT_WINDOW', '3600'),
        // Корень public/ — сюда скачиваются изображения товаров.
        'import_public_dir' => $root . '/public',
        // Параметры DBAL (Doctrine DBAL, без ORM).
        'db' => [
            'driver' => 'pdo_pgsql',
            'host' => $env('DB_HOST', 'db'),
            'port' => (int) $env('DB_PORT', '5432'),
            'dbname' => $env('DB_NAME', 'app'),
            'user' => $env('DB_USER', 'app'),
            'password' => $env('DB_PASSWORD', 'secret'),
            'charset' => 'utf8',
        ],
        // RabbitMQ: standalone-транспорт Symfony Messenger.
        'rabbitmq' => [
            'host' => $env('RABBITMQ_HOST', 'rabbitmq'),
            'port' => (int) $env('RABBITMQ_PORT', '5672'),
            'vhost' => $env('RABBITMQ_VHOST', '/'),
            'user' => $env('RABBITMQ_USER', 'guest'),
            'password' => $env('RABBITMQ_PASSWORD', 'guest'),
            'exchange' => $env('RABBITMQ_EXCHANGE', 'messages'),
            'queue' => $env('RABBITMQ_QUEUE', 'messages'),
        ],
    ],
];
