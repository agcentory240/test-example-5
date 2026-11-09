<?php

declare(strict_types=1);

namespace Tests\Integration\Middleware;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestApplication;

/**
 * Защита группы /api: 401 без валидного Bearer-токена, публичные пути открыты.
 */
final class AuthMiddlewareTest extends TestCase
{
    private TestApplication $app;

    private string $token;

    protected function setUp(): void
    {
        $this->app = TestApplication::create();
        $this->app->createUser('admin@admin', 'admin');
        $this->token = $this->app->token();
    }

    protected function tearDown(): void
    {
        $this->app->destroy();
    }

    public function testProductsWithoutAuthorizationReturns401(): void
    {
        $response = $this->app->request('GET', '/api/products');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['error' => 'Unauthorized'], $this->app->json($response));
    }

    public function testProductsWithInvalidTokenReturns401(): void
    {
        $response = $this->app->request('GET', '/api/products', [
            'Authorization' => 'Bearer invalid',
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testProductsWithValidTokenReturns200(): void
    {
        $response = $this->app->request('GET', '/api/products', [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame([], $this->app->json($response)['data']);
    }

    public function testHealthWithoutTokenReturns200(): void
    {
        $response = $this->app->request('GET', '/api/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok'], $this->app->json($response));
    }

    public function testDocsWithoutTokenReturns200(): void
    {
        $response = $this->app->request('GET', '/api/docs');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('swagger-ui', (string) $response->getBody());
    }

    public function testEmptyAuthorizationHeaderReturns401(): void
    {
        $response = $this->app->request('GET', '/api/products', ['Authorization' => '']);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testBearerWithoutTokenReturns401(): void
    {
        $response = $this->app->request('GET', '/api/products', ['Authorization' => 'Bearer']);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testBasicSchemeReturns401(): void
    {
        $response = $this->app->request('GET', '/api/products', [
            'Authorization' => 'Basic YWRtaW46YWRtaW4=',
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testOptionsPreflightPassesWithoutToken(): void
    {
        $response = $this->app->request('OPTIONS', '/api/products', [
            'Origin' => 'http://localhost:4200',
        ]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('http://localhost:4200', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
