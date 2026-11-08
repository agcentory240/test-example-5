<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Controller\ImportController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    // ВАЖНО: колбэк группы не static — Slim биндит его к контейнеру.
    $app->group('/api', function (RouteCollectorProxy $group): void {
        $group->get('/health', [HealthController::class, 'index']);
        $group->get('/health/db', [HealthController::class, 'db']);

        // Загрузка XLSX-файла импорта (заглушка, без постановки в очередь).
        $group->post('/import', [ImportController::class, 'upload']);
    });
};
