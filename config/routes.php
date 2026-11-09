<?php

declare(strict_types=1);

use App\Controller\AuthController;
use App\Controller\DocsController;
use App\Controller\HealthController;
use App\Controller\ImportController;
use App\Controller\ProductCleanupController;
use App\Controller\ProductController;
use App\Controller\TestMessengerController;
use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    // ВАЖНО: колбэк группы не static — Slim биндит его к контейнеру.
    $app->group('/api', function (RouteCollectorProxy $group): void {
        // JWT-авторизация: public-пути (login, health, docs) пропускаются внутри AuthMiddleware.
        $group->post('/login', [AuthController::class, 'login']);

        $group->get('/health', [HealthController::class, 'index']);
        $group->get('/health/db', [HealthController::class, 'db']);

        // Каталог: серверная пагинация, поиск по name (ILIKE), фильтр по цене.
        $group->get('/products', [ProductController::class, 'list']);
        $group->get('/products/{id}', [ProductController::class, 'show']);

        // Тестовая очистка: все товары + файлы public/uploads/products/ (204, без rate limit).
        $group->delete('/products', [ProductCleanupController::class, 'clearAll']);

        // Асинхронный импорт: файл сохраняется, ImportProductsMessage уходит в очередь (202 + jobId).
        // Rate limit: 10 запросов в час с одного IP (429 при превышении).
        $group->post('/import', [ImportController::class, 'upload'])->add(RateLimitMiddleware::class);

        // Статус задачи импорта: var/imports/{jobId}.json.
        $group->get('/import/{jobId}/status', [ImportController::class, 'status']);

        // ВРЕМЕННЫЙ роут: только парсинг XLSX в DTO (без БД и очереди).
        $group->post('/import/parse-test', [ImportController::class, 'parseTest']);

        // ВРЕМЕННЫЙ роут: проверка связки Messenger + RabbitMQ (Шаг 4.1).
        $group->get('/test-dispatch', [TestMessengerController::class, 'dispatch']);

        // Swagger UI + JSON-спека (генерируется из атрибутов контроллеров).
        $group->get('/docs', [DocsController::class, 'ui']);
        $group->get('/docs.json', [DocsController::class, 'json']);
    })->add(AuthMiddleware::class);
};
