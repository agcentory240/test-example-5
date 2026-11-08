<?php

declare(strict_types=1);

use App\Settings\Settings;
use App\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/** @var array{settings: array<string, mixed>} $config */
$config = require __DIR__ . '/settings.php';
$settings = $config['settings'];

$builder = new ContainerBuilder();
$builder->useAutowiring(true);

$builder->addDefinitions([
    'settings' => $settings,

    SettingsInterface::class => new Settings($settings),

    // Нужен middleware (CORS, JSON-ошибки) и AppFactory.
    ResponseFactoryInterface::class => new ResponseFactory(),

    // Doctrine DBAL: только подключение, без ORM.
    Connection::class => static function () use ($settings): Connection {
        /** @var array<string, mixed> $db */
        $db = $settings['db'];

        return DriverManager::getConnection($db);
    },

    LoggerInterface::class => static function () use ($settings): LoggerInterface {
        $logger = new Logger('app');
        $logger->pushHandler(new StreamHandler(
            'php://stdout',
            ($settings['debug'] ?? false) ? Level::Debug : Level::Info,
        ));

        return $logger;
    },

    // Все контроллеры создаются автовайрингом PHP-DI (зависимости — через конструктор).
]);

return $builder->build();
