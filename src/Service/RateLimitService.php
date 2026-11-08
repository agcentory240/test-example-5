<?php

declare(strict_types=1);

namespace App\Service;

use App\Settings\SettingsInterface;
use RuntimeException;

/**
 * Счётчик запросов по ключу (IP) в файле var/rate_limit/{hash}.json.
 * Хранит метки времени запросов внутри скользящего окна. MVP-решение без Redis.
 */
final class RateLimitService
{
    public function __construct(private readonly SettingsInterface $settings) {}

    /**
     * @return array{allowed: bool, limit: int, remaining: int, retryAfter: int}
     */
    public function hit(string $key): array
    {
        $limit = max(1, (int) $this->settings->get('rate_limit_max', 10));
        $window = max(1, (int) $this->settings->get('rate_limit_window', 3600));
        $file = $this->dir() . '/' . sha1($key) . '.json';

        $handle = fopen($file, 'c+');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Cannot open rate limit file "%s"', $file));
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException(sprintf('Cannot lock rate limit file "%s"', $file));
            }

            $now = time();
            $recent = $this->recentTimestamps((string) stream_get_contents($handle), $now, $window);

            if (count($recent) >= $limit) {
                return [
                    'allowed' => false,
                    'limit' => $limit,
                    'remaining' => 0,
                    'retryAfter' => max(1, min($recent) + $window - $now),
                ];
            }

            $recent[] = $now;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($recent, JSON_THROW_ON_ERROR));
            fflush($handle);

            return [
                'allowed' => true,
                'limit' => $limit,
                'remaining' => max(0, $limit - count($recent)),
                'retryAfter' => 0,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return list<int>
     */
    private function recentTimestamps(string $raw, int $now, int $window): array
    {
        if ($raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return [];
        }

        $recent = [];

        foreach ($data as $timestamp) {
            if (is_int($timestamp) && $timestamp > $now - $window) {
                $recent[] = $timestamp;
            }
        }

        return $recent;
    }

    private function dir(): string
    {
        $dir = (string) $this->settings->get('rate_limit_dir', sys_get_temp_dir() . '/rate_limit');

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create rate limit dir "%s"', $dir));
        }

        return $dir;
    }
}
