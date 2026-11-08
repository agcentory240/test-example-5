<?php

declare(strict_types=1);

use App\Controller\HealthController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    // ВАЖНО: колбэк группы не static — Slim биндит его к контейнеру.
    $app->group('/api', function (RouteCollectorProxy $group): void {
        $group->get('/health', [HealthController::class, 'index']);
        $group->get('/health/db', [HealthController::class, 'db']);
    });
};
