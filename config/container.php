<?php

declare(strict_types=1);

use App\Settings\Settings;
use App\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/** @var array{settings: array<string, mixed>} $config */
$config = require __DIR__ . '/settings.php';
$settings = $config['settings'];

$root = dirname(__DIR__);

$builder = new ContainerBuilder();
$builder->useAutowiring(true);

$builder->addDefinitions([
    'settings' => $settings,

    SettingsInterface::class => new Settings($settings),

    // Нужен middleware (CORS, JSON-ошибки) и AppFactory.
    ResponseFactoryInterface::class => new ResponseFactory(),

    // Doctrine DBAL: подключение.
    Connection::class => static function () use ($settings): Connection {
        /** @var array<string, mixed> $db */
        $db = $settings['db'];

        return DriverManager::getConnection($db);
    },

    // Doctrine ORM: attribute-маппинг (PHP 8 attributes), сущности — src/Entity.
    EntityManagerInterface::class => static function (ContainerInterface $c) use ($settings, $root): EntityManagerInterface {
        $proxyDir = $root . '/var/cache/doctrine/proxies';

        if (!is_dir($proxyDir)) {
            mkdir($proxyDir, 0o777, true);
        }

        $config = ORMSetup::createAttributeMetadataConfiguration(
            [$root . '/src/Entity'],
            (bool) ($settings['debug'] ?? false),
            $proxyDir,
        );

        // Колонки в БД — snake_case (external_code, purchase_price, created_at).
        $config->setNamingStrategy(new UnderscoreNamingStrategy());

        return new EntityManager($c->get(Connection::class), $config);
    },

    EntityManager::class => static fn(ContainerInterface $c): EntityManager => $c->get(EntityManagerInterface::class),

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
