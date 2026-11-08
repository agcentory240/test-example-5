<?php

declare(strict_types=1);

namespace App\Settings;

final class Settings implements SettingsInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private readonly array $values)
    {
    }

    public function getAppEnv(): string
    {
        return (string) ($this->values['app_env'] ?? 'dev');
    }

    public function isDebug(): bool
    {
        return (bool) ($this->values['debug'] ?? false);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
