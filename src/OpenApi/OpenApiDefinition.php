<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Spec as OA;

/**
 * Корневое описание API (генерируется swagger-php в GET /api/docs.json).
 */
#[OA\Info(
    title: 'Product Import API',
    version: '1.0.0',
    description: 'Импорт товаров из XLSX и каталог с пагинацией, поиском и фильтрами.',
)]
#[OA\Server(url: '/', description: 'Текущий хост')]
final class OpenApiDefinition {}
