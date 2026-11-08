<?php

declare(strict_types=1);

namespace App\Middleware;

use Slim\Middleware\BodyParsingMiddleware;

/**
 * Парсит тело запроса с Content-Type: application/json в $request->getParsedBody().
 * Наследует стандартный middlewares Slim, но оставляет только JSON-парсер.
 */
final class JsonBodyParsingMiddleware extends BodyParsingMiddleware
{
    protected function registerDefaultBodyParsers(): void
    {
        $this->registerBodyParser('application/json', static function (mixed $input): ?array {
            if (!is_string($input) || trim($input) === '') {
                return null;
            }

            $result = json_decode($input, true);

            return is_array($result) ? $result : null;
        });
    }
}
