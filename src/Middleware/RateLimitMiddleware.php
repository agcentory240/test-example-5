<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\RateLimitService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Лимит на создание импортов: N запросов за окно (по IP).
 * Добавляется только на POST /api/import.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimitService $limiter,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $ip = $this->clientIp($request);
        $result = $this->limiter->hit('import:' . $ip);

        if (!$result['allowed']) {
            $this->logger->warning('Rate limit exceeded', [
                'ip' => $ip,
                'path' => $request->getUri()->getPath(),
                'retryAfter' => $result['retryAfter'],
            ]);

            $response = $this->responseFactory->createResponse(429);
            $response->getBody()->write((string) json_encode(
                ['error' => 'too many requests', 'retryAfter' => $result['retryAfter']],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string) $result['retryAfter'])
                ->withHeader('X-RateLimit-Limit', (string) $result['limit'])
                ->withHeader('X-RateLimit-Remaining', '0');
        }

        return $handler->handle($request)
            ->withHeader('X-RateLimit-Limit', (string) $result['limit'])
            ->withHeader('X-RateLimit-Remaining', (string) $result['remaining']);
    }

    private function clientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        $ip = (string) ($serverParams['REMOTE_ADDR'] ?? '');

        return $ip !== '' ? $ip : 'unknown';
    }
}
