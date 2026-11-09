<?php

declare(strict_types=1);

namespace Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestApplication;

/**
 * Импорт и очистка каталога доступны только с JWT.
 */
final class ImportAccessTest extends TestCase
{
    private const JOB_ID = '11111111-2222-4333-8444-555555555555';

    private TestApplication $app;

    protected function setUp(): void
    {
        $this->app = TestApplication::create();
        $this->app->createUser('admin@admin', 'admin');
    }

    protected function tearDown(): void
    {
        $this->app->destroy();
    }

    public function testUploadWithoutTokenReturns401(): void
    {
        $response = $this->app->request('POST', '/api/import');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['error' => 'Unauthorized'], $this->app->json($response));
    }

    public function testImportStatusWithoutTokenReturns401(): void
    {
        $response = $this->app->request('GET', '/api/import/' . self::JOB_ID . '/status');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['error' => 'Unauthorized'], $this->app->json($response));
    }

    public function testCleanupWithoutTokenReturns401(): void
    {
        $response = $this->app->request('DELETE', '/api/products');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['error' => 'Unauthorized'], $this->app->json($response));
    }

    public function testImportStatusWithTokenIsNotUnauthorized(): void
    {
        $response = $this->app->request('GET', '/api/import/' . self::JOB_ID . '/status', [
            'Authorization' => 'Bearer ' . $this->app->token(),
        ]);

        self::assertNotSame(401, $response->getStatusCode());
    }
}
