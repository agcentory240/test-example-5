<?php

declare(strict_types=1);

use App\Middleware\CorsMiddleware;
use App\Middleware\JsonBodyParsingMiddleware;
use App\Middleware\JsonErrorHandler;
use App\Settings\SettingsInterface;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

/** @var \Psr\Container\ContainerInterface $container */
$container = require __DIR__ . '/../config/container.php';

// AppFactory::createFromContainer — контроллеры и middleware резолвятся из PHP-DI.
$app = AppFactory::createFromContainer($container);
$app->addRoutingMiddleware();

$settings = $container->get(SettingsInterface::class);

// Ошибки — всегда JSON, не HTML.
$errorMiddleware = $app->addErrorMiddleware(
    $settings->isDebug(),
    (bool) $settings->get('log_errors', true),
    (bool) $settings->get('log_error_details', $settings->isDebug()),
);
$errorMiddleware->setDefaultErrorHandler($container->get(JsonErrorHandler::class));

// Порядок: middleware, добавленный позже, выполняется раньше.
// CORS — снаружи (заголовки и на ошибках), затем JSON body parser.
$app->add($container->get(JsonBodyParsingMiddleware::class));
$app->add($container->get(CorsMiddleware::class));

(require __DIR__ . '/../config/routes.php')($app);

$app->run();
