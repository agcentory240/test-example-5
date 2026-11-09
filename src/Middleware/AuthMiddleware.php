<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\AuthService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Проверка JWT на защищённых роутах.
 * Публичные пути (логин, health, Swagger) и preflight OPTIONS пропускаются.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    private const PUBLIC_PATHS = [
        '/api/login',
        '/api/docs',
        '/api/docs.json',
    ];

    public function __construct(
        private readonly AuthService $auth,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        if ($request->getMethod() === 'OPTIONS' || $this->isPublic($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        $token = $this->extractToken($request->getHeaderLine('Authorization'));
        $payload = $token !== null ? $this->auth->verifyToken($token) : null;

        if ($payload === null) {
            $response = $this->responseFactory->createResponse(401);
            $response->getBody()->write((string) json_encode(
                ['error' => 'Unauthorized'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));

            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request->withAttribute('user', $payload));
    }

    private function isPublic(string $path): bool
    {
        if (str_starts_with($path, '/api/health')) {
            return true;
        }

        return in_array($path, self::PUBLIC_PATHS, true);
    }

    private function extractToken(string $header): ?string
    {
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
