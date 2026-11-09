<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ProductCleanupService;
use OpenApi\Spec as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Тестовый эндпоинт: полная очистка каталога и загруженных файлов.
 */
final class ProductCleanupController
{
    public function __construct(private readonly ProductCleanupService $cleanup) {}

    #[OA\Operation\Delete(
        path: '/api/products',
        summary: 'Удалить все товары, атрибуты, изображения и файлы (тестовый функционал)',
        tags: ['Products'],
        responses: [
            new OA\Response(response: 204, description: 'Каталог очищен'),
        ],
    )]
    public function clearAll(Request $request, Response $response): Response
    {
        $this->cleanup->clearAll();

        return $response->withStatus(204);
    }
}
