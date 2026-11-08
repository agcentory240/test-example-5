<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TestMessage;

final class TestMessageHandler
{
    public function __invoke(TestMessage $message): void
    {
        $line = sprintf('[%s] Handled: %s', date('Y-m-d H:i:s'), $message->text);

        error_log($line);

        $logDir = dirname(__DIR__, 2) . '/var/log';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0o777, true);
        }

        file_put_contents($logDir . '/messenger.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
