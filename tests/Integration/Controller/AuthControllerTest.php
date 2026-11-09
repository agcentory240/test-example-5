<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestApplication;

/**
 * POST /api/login: проверка кредов и выдачи JWT через реальный Slim-стек.
 */
final class AuthControllerTest extends TestCase
{
    private const EMAIL = 'admin@admin';

    private const PASSWORD = 'admin';

    private TestApplication $app;

    protected function setUp(): void
    {
        $this->app = TestApplication::create();
        $this->app->createUser(self::EMAIL, self::PASSWORD);
    }

    protected function tearDown(): void
    {
        $this->app->destroy();
    }

    public function testLoginWithValidCredentialsReturnsToken(): void
    {
        $response = $this->app->request('POST', '/api/login', [], [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $payload = $this->app->json($response);

        self::assertArrayHasKey('token', $payload);
        self::assertIsString($payload['token']);
        self::assertNotSame('', $payload['token']);
    }

    public function testLoginWithWrongPasswordReturns401(): void
    {
        $response = $this->app->request('POST', '/api/login', [], [
            'email' => self::EMAIL,
            'password' => 'wrong-password',
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['error' => 'Invalid credentials'], $this->app->json($response));
    }

    public function testLoginWithUnknownEmailReturns401(): void
    {
        $response = $this->app->request('POST', '/api/login', [], [
            'email' => 'unknown@example.com',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['error' => 'Invalid credentials'], $this->app->json($response));
    }

    public function testLoginWithoutPasswordReturns400(): void
    {
        $response = $this->app->request('POST', '/api/login', [], ['email' => self::EMAIL]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['error' => 'email and password are required'],
            $this->app->json($response),
        );
    }

    public function testLoginWithoutEmailReturns400(): void
    {
        $response = $this->app->request('POST', '/api/login', [], ['password' => self::PASSWORD]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testLoginWithEmptyCredentialsReturns400(): void
    {
        $response = $this->app->request('POST', '/api/login', [], [
            'email' => '',
            'password' => '',
        ]);

        self::assertSame(400, $response->getStatusCode());
    }
}
