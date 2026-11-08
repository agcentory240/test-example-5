<?php

declare(strict_types=1);

use App\Message\TestMessage;
use App\MessageHandler\TestMessageHandler;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpReceiver;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpSender;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection as AmqpConnection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

/**
 * Standalone-сборка Symfony Messenger для Slim (без FrameworkBundle).
 *
 * @param array<string, mixed> $settings
 *
 * @return array<string, mixed>
 */
return static function (array $settings): array {
    /** @var array{host: string, port: int, vhost: string, user: string, password: string, exchange: string, queue: string} $rabbit */
    $rabbit = $settings['rabbitmq'];

    return [
        // AMQP-соединение: exchange + очередь объявляются автоматически (auto_setup).
        'messenger.amqp' => static fn(): AmqpConnection => new AmqpConnection(
            [
                'host' => $rabbit['host'],
                'port' => $rabbit['port'],
                'vhost' => $rabbit['vhost'],
                'login' => $rabbit['user'],
                'password' => $rabbit['password'],
            ],
            [
                'name' => $rabbit['exchange'],
                'type' => 'fanout',
            ],
            [
                $rabbit['queue'] => [
                    'binding_keys' => [$rabbit['queue']],
                ],
            ],
        ),

        'messenger.sender.async' => static fn(ContainerInterface $c): AmqpSender => new AmqpSender(
            $c->get('messenger.amqp'),
        ),

        'messenger.receiver.async' => static fn(ContainerInterface $c): AmqpReceiver => new AmqpReceiver(
            $c->get('messenger.amqp'),
        ),

        // Маршрутизация: TestMessage -> транспорт async. Всё остальное не отправляется.
        'messenger.senders_locator' => static fn(ContainerInterface $c): SendersLocatorInterface => new class ($c->get('messenger.sender.async')) implements SendersLocatorInterface {
            public function __construct(private readonly SenderInterface $sender) {}

            public function getSenders(Envelope $envelope): iterable
            {
                if ($envelope->getMessage() instanceof TestMessage) {
                    yield 'async' => $this->sender;
                }
            }
        },

        // Обработчики сообщений.
        'messenger.handlers_locator' => static fn(): HandlersLocator => new HandlersLocator([
            TestMessage::class => [new TestMessageHandler()],
        ]),

        // MessageBus собирается вручную: отправка в транспорт + вызов локального обработчика.
        MessageBusInterface::class => static fn(ContainerInterface $c): MessageBusInterface => new MessageBus([
            new SendMessageMiddleware($c->get('messenger.senders_locator')),
            new HandleMessageMiddleware($c->get('messenger.handlers_locator')),
        ]),
    ];
};
