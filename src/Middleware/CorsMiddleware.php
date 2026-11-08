<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Settings\SettingsInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CORS: разрешает запросы с фронтенда (origins — settings.cors_allowed_origins).
 * Preflight (OPTIONS) обрабатывается сразу, без похода в роутинг.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    private const ALLOWED_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
    private const ALLOWED_HEADERS = 'Accept, Authorization, Content-Type, X-Requested-With';
    private const MAX_AGE = '86400';

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly SettingsInterface $settings,
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $allowedOrigin = $this->resolveAllowedOrigin($request->getHeaderLine('Origin'));

        $response = $request->getMethod() === 'OPTIONS'
            ? $this->responseFactory->createResponse(204)
            : $handler->handle($request);

        if ($allowedOrigin === null) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
            ->withHeader('Access-Control-Allow-Methods', self::ALLOWED_METHODS)
            ->withHeader('Access-Control-Allow-Headers', self::ALLOWED_HEADERS)
            ->withHeader('Access-Control-Max-Age', self::MAX_AGE)
            ->withAddedHeader('Vary', 'Origin');
    }

    private function resolveAllowedOrigin(string $origin): ?string
    {
        if ($origin === '') {
            return null;
        }

        /** @var array<int, string> $allowedOrigins */
        $allowedOrigins = (array) $this->settings->get('cors_allowed_origins', []);

        if (in_array('*', $allowedOrigins, true)) {
            return '*';
        }

        return in_array($origin, $allowedOrigins, true) ? $origin : null;
    }
}
