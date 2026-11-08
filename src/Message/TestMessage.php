<?php

declare(strict_types=1);

namespace App\Message;

final class TestMessage
{
    public function __construct(
        public string $text,
    ) {}
}
