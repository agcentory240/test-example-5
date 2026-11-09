<?php

declare(strict_types=1);

namespace Tests\Integration\Middleware;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestApplication;

/**
 * CORS-preflight должен разрешать кастомный заголовок ngrok-skip-browser-warning:
 * без него браузер отклонит GET-запросы статуса импорта, которые фронтенд
 * отправляет через бесплатный ngrok (иначе ngrok отдаёт HTML-заглушку).
 */
final class CorsMiddlewareTest extends TestCase
{
    private const ORIGIN = 'http://localhost:4200';
    private const JOB_ID = '11111111-2222-4333-8444-555555555555';

    private TestApplication $app;

    protected function setUp(): void
    {
        $this->app = TestApplication::create();
    }

    protected function tearDown(): void
    {
        $this->app->destroy();
    }

    public function testPreflightAllowsNgrokSkipBrowserWarningHeader(): void
    {
        $response = $this->app->request('OPTIONS', '/api/import/' . self::JOB_ID . '/status', [
            'Origin' => self::ORIGIN,
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'authorization, ngrok-skip-browser-warning',
        ]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));

        $allowedHeaders = $response->getHeaderLine('Access-Control-Allow-Headers');
        self::assertStringContainsString('ngrok-skip-browser-warning', $allowedHeaders);
        self::assertStringContainsString('Authorization', $allowedHeaders);
        self::assertStringContainsString('Content-Type', $allowedHeaders);
    }

    public function testPreflightWithoutOriginHasNoCorsHeaders(): void
    {
        $response = $this->app->request('OPTIONS', '/api/import/' . self::JOB_ID . '/status', [
            'Access-Control-Request-Method' => 'GET',
        ]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testDisallowedOriginGetsNoCorsHeaders(): void
    {
        $response = $this->app->request('OPTIONS', '/api/import/' . self::JOB_ID . '/status', [
            'Origin' => 'https://evil.example.com',
            'Access-Control-Request-Method' => 'GET',
        ]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
