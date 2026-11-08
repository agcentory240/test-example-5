<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Controller\ImportController;
use App\Controller\TestMessengerController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    // ВАЖНО: колбэк группы не static — Slim биндит его к контейнеру.
    $app->group('/api', function (RouteCollectorProxy $group): void {
        $group->get('/health', [HealthController::class, 'index']);
        $group->get('/health/db', [HealthController::class, 'db']);

        // Асинхронный импорт: файл сохраняется, ImportProductsMessage уходит в очередь (202 + jobId).
        $group->post('/import', [ImportController::class, 'upload']);

        // Статус задачи импорта: var/imports/{jobId}.json.
        $group->get('/import/{jobId}/status', [ImportController::class, 'status']);

        // ВРЕМЕННЫЙ роут: только парсинг XLSX в DTO (без БД и очереди).
        $group->post('/import/parse-test', [ImportController::class, 'parseTest']);

        // ВРЕМЕННЫЙ роут: проверка связки Messenger + RabbitMQ (Шаг 4.1).
        $group->get('/test-dispatch', [TestMessengerController::class, 'dispatch']);
    });
};
