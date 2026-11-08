<?php

declare(strict_types=1);

namespace App\Controller;

use App\Settings\SettingsInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

final class HealthController
{
    public function __construct(
        private readonly SettingsInterface $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $this->logger->info('Health check', [
            'env' => $this->settings->getAppEnv(),
            'path' => $request->getUri()->getPath(),
        ]);

        $response->getBody()->write(json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
