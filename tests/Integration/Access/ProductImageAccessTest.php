<?php

declare(strict_types=1);

namespace Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProductFactory;
use Tests\Support\TestApplication;

/**
 * Доступ к товарам и изображениям через API: без JWT нельзя получить ни список,
 * ни карточку с images. Прямая отдача файлов nginx-ом — известное ограничение.
 */
final class ProductImageAccessTest extends TestCase
{
    private TestApplication $app;

    private int $productId;

    protected function setUp(): void
    {
        $this->app = TestApplication::create();
        $this->app->createUser('admin@admin', 'admin');

        $product = ProductFactory::create(
            $this->app->em,
            'A-1',
            'Bermuda blue',
            100.0,
            null,
            ['Бренд' => 'Zara'],
            ['https://example.com/1.jpg'],
        );

        $this->productId = (int) $product->getId();
    }

    protected function tearDown(): void
    {
        $this->app->destroy();
    }

    public function testProductDetailWithImagesWithoutTokenReturns401(): void
    {
        $response = $this->app->request('GET', '/api/products/' . $this->productId);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['error' => 'Unauthorized'], $this->app->json($response));
    }

    public function testProductListWithImagesWithoutTokenReturns401(): void
    {
        $response = $this->app->request('GET', '/api/products');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['error' => 'Unauthorized'], $this->app->json($response));
    }

    public function testProductDetailWithImagesReturns200WithToken(): void
    {
        $response = $this->app->request('GET', '/api/products/' . $this->productId, [
            'Authorization' => 'Bearer ' . $this->app->token(),
        ]);

        self::assertSame(200, $response->getStatusCode());

        $payload = $this->app->json($response);

        self::assertCount(1, $payload['images']);
        self::assertSame('https://example.com/1.jpg', $payload['images'][0]['url']);
    }

    /**
     * Файлы public/uploads/products/* отдаются nginx-ом как статика, без PHP и без JWT:
     * маршрута аутентификации в nginx нет. Для тестового задания это допустимо,
     * в проде нужно закрывать подписанными URL или отдельным middleware
     * (см. DECISIONS.md, п. 92).
     */
    public function testNginxServesUploadsStaticallyWithoutAuth(): void
    {
        $config = file_get_contents(dirname(__DIR__, 3) . '/docker/nginx/default.conf');

        self::assertIsString($config);
        self::assertStringContainsString('try_files $uri /index.php$is_args$args;', $config);
        self::assertStringNotContainsString('auth_request', $config);
    }
}
