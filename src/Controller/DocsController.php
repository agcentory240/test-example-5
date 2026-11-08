<?php

declare(strict_types=1);

namespace App\Controller;

use OpenApi\Builder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Swagger UI (/api/docs) и JSON-спека (/api/docs.json).
 * Спека собирается на лету из атрибутов контроллеров.
 */
final class DocsController
{
    private const SWAGGER_UI_VERSION = '5.17.14';

    public function __construct(private readonly LoggerInterface $logger) {}

    public function ui(Request $request, Response $response): Response
    {
        $cssUrl = sprintf('https://unpkg.com/swagger-ui-dist@%1$s/swagger-ui.css', self::SWAGGER_UI_VERSION);
        $jsUrl = sprintf('https://unpkg.com/swagger-ui-dist@%1$s/swagger-ui-bundle.js', self::SWAGGER_UI_VERSION);

        $html = <<<HTML
            <!doctype html>
            <html lang="ru">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>API docs</title>
                <link rel="stylesheet" href="{$cssUrl}">
            </head>
            <body>
                <div id="swagger-ui"></div>
                <script src="{$jsUrl}"></script>
                <script>
                    window.ui = SwaggerUIBundle({
                        url: '/api/docs.json',
                        dom_id: '#swagger-ui',
                        defaultModelsExpandDepth: -1
                    });
                </script>
            </body>
            </html>
            HTML;

        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function json(Request $request, Response $response): Response
    {
        $builder = new Builder();
        $builder->addSource(dirname(__DIR__) . '/Controller');
        $builder->addSource(dirname(__DIR__) . '/OpenApi');

        $result = $builder->build();

        if (!$result->isValid()) {
            $this->logger->error('OpenAPI spec generation failed', ['spec' => $result->toArray()]);

            $response->getBody()->write((string) json_encode(
                ['error' => 'cannot generate openapi spec'],
                JSON_THROW_ON_ERROR,
            ));

            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write($result->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
