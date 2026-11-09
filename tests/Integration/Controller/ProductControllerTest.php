<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Controller\ProductController;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\DoctrineTestHelper;
use Tests\Support\ProductFactory;

final class ProductControllerTest extends TestCase
{
    private ProductController $controller;

    protected function setUp(): void
    {
        $em = DoctrineTestHelper::createEntityManager();

        ProductFactory::create($em, 'A-1', 'Bermuda blue', 100.0, 80.0, ['Бренд' => 'Zara'], ['https://example.com/1.jpg']);
        ProductFactory::create($em, 'A-2', 'Bermuda white', 200.0);
        ProductFactory::create($em, 'A-3', 'T-shirt', 50.0);

        $this->controller = new ProductController(new ProductRepository($em));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(string $uri, array $query = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $uri)
            ->withQueryParams($query);
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    public function testListReturnsDataAndMeta(): void
    {
        $response = $this->controller->list($this->request('/api/products'), $this->response());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $payload = $this->decode($response);

        self::assertCount(3, $payload['data']);
        self::assertSame(['page' => 1, 'limit' => 20, 'total' => 3, 'totalPages' => 1], $payload['meta']);
        self::assertSame(['url' => 'https://example.com/1.jpg', 'path' => null], $payload['data'][0]['images'][0]);
        self::assertArrayNotHasKey('attributes', $payload['data'][0]);
    }

    public function testListAppliesFiltersAndPagination(): void
    {
        $response = $this->controller->list(
            $this->request('/api/products', ['name' => 'bermuda', 'page' => '2', 'limit' => '1']),
            $this->response(),
        );

        $payload = $this->decode($response);

        self::assertSame(['page' => 2, 'limit' => 1, 'total' => 2, 'totalPages' => 2], $payload['meta']);
        self::assertCount(1, $payload['data']);
        self::assertSame('A-2', $payload['data'][0]['externalCode']);
    }

    public function testListClampsLimitToMaximum(): void
    {
        $response = $this->controller->list(
            $this->request('/api/products', ['limit' => '1000']),
            $this->response(),
        );

        self::assertSame(100, $this->decode($response)['meta']['limit']);
    }

    public function testListReturnsZeroTotalPagesForEmptyResult(): void
    {
        $response = $this->controller->list(
            $this->request('/api/products', ['name' => 'nothing']),
            $this->response(),
        );

        self::assertSame(['page' => 1, 'limit' => 20, 'total' => 0, 'totalPages' => 0], $this->decode($response)['meta']);
    }

    public function testListRejectsNonNumericPrice(): void
    {
        $response = $this->controller->list(
            $this->request('/api/products', ['price_min' => 'abc']),
            $this->response(),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'Parameter "price_min" must be numeric'], $this->decode($response));
    }

    public function testListRejectsInvertedPriceRange(): void
    {
        $response = $this->controller->list(
            $this->request('/api/products', ['price_min' => '200', 'price_max' => '100']),
            $this->response(),
        );

        self::assertSame(400, $response->getStatusCode());
    }

    public function testShowReturnsProductWithAttributesAndImages(): void
    {
        $response = $this->controller->show($this->request('/api/products/1'), $this->response(), ['id' => '1']);

        self::assertSame(200, $response->getStatusCode());

        $payload = $this->decode($response);

        self::assertSame('A-1', $payload['externalCode']);
        self::assertEqualsWithDelta(20.0, (float) $payload['discount'], 0.001);
        self::assertSame(['Бренд' => 'Zara'], $payload['attributes']);
        self::assertCount(1, $payload['images']);
    }

    public function testShowReturns404ForMissingProduct(): void
    {
        $response = $this->controller->show($this->request('/api/products/999'), $this->response(), ['id' => '999']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'product not found'], $this->decode($response));
    }

    public function testShowReturns400ForInvalidId(): void
    {
        $response = $this->controller->show($this->request('/api/products/abc'), $this->response(), ['id' => 'abc']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'invalid product id'], $this->decode($response));
    }
}
