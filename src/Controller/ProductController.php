<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ProductViewDTO;
use App\Entity\Product;
use App\Repository\ProductRepository;
use InvalidArgumentException;
use OpenApi\Spec as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Тонкий контроллер каталога: параметры → сервис/репозиторий → JSON.
 */
final class ProductController
{
    private const DEFAULT_PAGE = 1;

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 100;

    public function __construct(private readonly ProductRepository $products) {}

    #[OA\Operation\Get(
        path: '/api/products',
        summary: 'Список товаров с пагинацией, поиском по названию и фильтром по цене',
        tags: ['Products'],
        parameters: [
            new OA\Parameter(
                name: 'page',
                in: 'query',
                description: 'Номер страницы (с 1)',
                schema: new OA\Schema(type: 'integer', default: self::DEFAULT_PAGE),
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                description: 'Размер страницы (максимум 100)',
                schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT),
            ),
            new OA\Parameter(
                name: 'name',
                in: 'query',
                description: 'Поиск по подстроке названия (ILIKE)',
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'price_min',
                in: 'query',
                description: 'Минимальная цена',
                schema: new OA\Schema(type: 'number'),
            ),
            new OA\Parameter(
                name: 'price_max',
                in: 'query',
                description: 'Максимальная цена',
                schema: new OA\Schema(type: 'number'),
            ),
            new OA\Parameter(
                name: 'sort',
                in: 'query',
                description: 'Поле сортировки',
                schema: new OA\Schema(type: 'string', enum: ['id', 'name', 'price']),
            ),
            new OA\Parameter(
                name: 'order',
                in: 'query',
                description: 'Направление сортировки (ASC/DESC)',
                schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'asc'),
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'data + meta (page, limit, total, totalPages)'),
            new OA\Response(response: 400, description: 'Некорректные параметры запроса'),
        ],
    )]
    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        $page = max(self::DEFAULT_PAGE, $this->intParam($params['page'] ?? null, self::DEFAULT_PAGE));
        $limit = $this->intParam($params['limit'] ?? null, self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $name = isset($params['name']) && is_string($params['name']) ? trim($params['name']) : null;
        $sort = isset($params['sort']) && is_string($params['sort']) ? $params['sort'] : 'id';
        $order = isset($params['order']) && strtolower((string) $params['order']) === 'desc' ? 'desc' : 'asc';

        try {
            $priceMin = $this->floatParam($params['price_min'] ?? null, 'price_min');
            $priceMax = $this->floatParam($params['price_max'] ?? null, 'price_max');
        } catch (InvalidArgumentException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }

        if ($priceMin !== null && $priceMax !== null && $priceMin > $priceMax) {
            return $this->json($response, ['error' => 'price_min must be less than or equal to price_max'], 400);
        }

        $result = $this->products->paginate($name, $priceMin, $priceMax, $page, $limit, $sort, $order);

        $productIds = array_map(
            static fn(Product $product): int => (int) $product->getId(),
            $result['items'],
        );
        $images = $this->products->findImagesByProductIds($productIds);

        $data = array_map(
            static fn(Product $product): array => ProductViewDTO::summary(
                $product,
                $images[(int) $product->getId()] ?? [],
            )->toArray(),
            $result['items'],
        );

        $total = $result['total'];

        return $this->json($response, [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => $total === 0 ? 0 : (int) ceil($total / $limit),
            ],
        ], 200);
    }

    /**
     * @param array<string, string> $args
     */
    #[OA\Operation\Get(
        path: '/api/products/{id}',
        summary: 'Карточка товара с атрибутами и изображениями',
        tags: ['Products'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                description: 'Идентификатор товара',
                schema: new OA\Schema(type: 'integer'),
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Товар: attributes (key-value), images (url + path), discount'),
            new OA\Response(response: 404, description: 'Товар не найден'),
        ],
    )]
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);

        if ($id <= 0) {
            return $this->json($response, ['error' => 'invalid product id'], 400);
        }

        $product = $this->products->findWithDetails($id);

        if ($product === null) {
            return $this->json($response, ['error' => 'product not found'], 404);
        }

        return $this->json($response, ProductViewDTO::detail($product)->toArray(), 200);
    }

    private function intParam(mixed $value, int $default): int
    {
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            return $default;
        }

        return (int) $value;
    }

    private function floatParam(mixed $value, string $name): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_scalar($value) || !is_numeric((string) $value)) {
            throw new InvalidArgumentException(sprintf('Parameter "%s" must be numeric', $name));
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(Response $response, array $payload, int $status): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
