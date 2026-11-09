<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Тонкий контроллер авторизации: принимает креды, делегирует в AuthService.
 */
final class AuthController
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->json($response, ['error' => 'Invalid request body'], 400);
        }

        $email = isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';
        $password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';

        if ($email === '' || $password === '') {
            return $this->json($response, ['error' => 'email and password are required'], 400);
        }

        $token = $this->auth->authenticate($email, $password);

        if ($token === null) {
            return $this->json($response, ['error' => 'Invalid credentials'], 401);
        }

        return $this->json($response, ['token' => $token]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
