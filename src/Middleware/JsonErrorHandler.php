<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpException;
use Throwable;

/**
 * Обработчик ошибок Slim: всегда отдаёт JSON {"error": "..."}, а не HTML.
 */
final class JsonErrorHandler
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function __invoke(
        Request $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): Response {
        $statusCode = $exception instanceof HttpException ? $exception->getCode() : 500;

        if ($statusCode < 400 || $statusCode > 599) {
            $statusCode = 500;
        }

        $payload = [
            'error' => $exception instanceof HttpException || $displayErrorDetails
                ? $exception->getMessage()
                : 'Internal Server Error',
        ];

        if ($displayErrorDetails) {
            $payload['exception'] = $exception::class;
        }

        $response = $this->responseFactory->createResponse($statusCode);
        $response->getBody()->write(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
