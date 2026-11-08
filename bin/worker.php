#!/usr/bin/env php
<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

require __DIR__ . '/../vendor/autoload.php';

/** @var ContainerInterface $container */
$container = require __DIR__ . '/../config/container.php';

/**
 * Worker сам крутит бесконечный цикл: receiver->get() -> bus->dispatch() -> ack/reject.
 * ReceivedStamp внутри Worker не даёт SendMessageMiddleware отправить сообщение обратно в очередь.
 */
$worker = new Worker(
    ['async' => $container->get('messenger.receiver.async')],
    $container->get(MessageBusInterface::class),
    logger: $container->get(LoggerInterface::class),
);

$container->get(LoggerInterface::class)->info('Messenger worker started', ['transport' => 'async']);

$worker->run(['sleep' => 1_000_000]);
