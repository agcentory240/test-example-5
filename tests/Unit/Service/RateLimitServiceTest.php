<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\RateLimitService;
use App\Settings\Settings;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempDir;

final class RateLimitServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('rate_limit_');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    private function service(int $max = 2, int $window = 60): RateLimitService
    {
        return new RateLimitService(new Settings([
            'rate_limit_dir' => $this->dir,
            'rate_limit_max' => $max,
            'rate_limit_window' => $window,
        ]));
    }

    public function testBlocksAfterLimitAndReturnsRetryAfter(): void
    {
        $service = $this->service(max: 2);

        $first = $service->hit('127.0.0.1');
        self::assertTrue($first['allowed']);
        self::assertSame(1, $first['remaining']);
        self::assertSame(0, $first['retryAfter']);

        $second = $service->hit('127.0.0.1');
        self::assertTrue($second['allowed']);
        self::assertSame(0, $second['remaining']);

        $third = $service->hit('127.0.0.1');
        self::assertFalse($third['allowed']);
        self::assertSame(0, $third['remaining']);
        self::assertSame(2, $third['limit']);
        self::assertGreaterThan(0, $third['retryAfter']);
        self::assertLessThanOrEqual(60, $third['retryAfter']);
    }

    public function testDifferentKeysHaveIndependentCounters(): void
    {
        $service = $this->service(max: 1);

        self::assertTrue($service->hit('10.0.0.1')['allowed']);
        self::assertFalse($service->hit('10.0.0.1')['allowed']);
        self::assertTrue($service->hit('10.0.0.2')['allowed']);
    }

    public function testTimestampsOutsideWindowAreDiscarded(): void
    {
        $service = $this->service(max: 1, window: 60);

        $file = $this->dir . '/' . sha1('old-client') . '.json';
        file_put_contents($file, json_encode([time() - 3600], JSON_THROW_ON_ERROR));

        $result = $service->hit('old-client');

        self::assertTrue($result['allowed']);
        self::assertSame(0, $result['remaining']);
    }

    public function testCorruptedCounterFileIsTreatedAsEmpty(): void
    {
        $service = $this->service(max: 1);

        $file = $this->dir . '/' . sha1('corrupted') . '.json';
        file_put_contents($file, 'not-json');

        self::assertTrue($service->hit('corrupted')['allowed']);
    }
}
