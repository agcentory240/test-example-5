<?php

declare(strict_types=1);

namespace App\Controller;

use App\Settings\SettingsInterface;
use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

final class HealthController
{
    public function __construct(
        private readonly SettingsInterface $settings,
        private readonly LoggerInterface $logger,
        private readonly Connection $connection,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $this->logger->info('Health check', [
            'env' => $this->settings->getAppEnv(),
            'path' => $request->getUri()->getPath(),
        ]);

        $response->getBody()->write(json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function db(Request $request, Response $response): Response
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
        } catch (Throwable $e) {
            $this->logger->error('DB health check failed', ['exception' => $e]);

            $response->getBody()->write(json_encode(['db' => 'error'], JSON_THROW_ON_ERROR));

            return $response
                ->withStatus(503)
                ->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['db' => 'ok'], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
