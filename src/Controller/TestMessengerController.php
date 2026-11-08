<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\TestMessage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Symfony\Component\Messenger\MessageBusInterface;

final class TestMessengerController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {}

    public function dispatch(Request $request, Response $response): Response
    {
        $this->bus->dispatch(new TestMessage('Hello RabbitMQ'));

        $response->getBody()->write(json_encode(['status' => 'dispatched'], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
