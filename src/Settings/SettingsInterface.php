<?php

declare(strict_types=1);

namespace App\Settings;

interface SettingsInterface
{
    public function getAppEnv(): string;

    public function isDebug(): bool;

    public function get(string $key, mixed $default = null): mixed;

    /**
     * @return array<string, mixed>
     */
    public function all(): array;
}
