<?php

declare(strict_types=1);

namespace App\Service;

use App\Settings\SettingsInterface;
use DateTimeImmutable;
use RuntimeException;

/**
 * Статус задачи импорта в var/imports/{jobId}.json.
 * Файл — единственный источник правды для GET /api/import/{jobId}/status.
 */
final class ImportJobStatusStorage
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    private const JOB_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly SettingsInterface $settings) {}

    public static function isValidJobId(string $jobId): bool
    {
        return preg_match(self::JOB_ID_PATTERN, $jobId) === 1;
    }

    public function markQueued(string $jobId): void
    {
        $this->write($jobId, [
            'status' => self::STATUS_QUEUED,
            'imported' => 0,
            'updated' => 0,
            'errors' => [],
            'createdAt' => $this->now(),
        ]);
    }

    public function markProcessing(string $jobId): void
    {
        $this->patch($jobId, ['status' => self::STATUS_PROCESSING, 'startedAt' => $this->now()]);
    }

    /**
     * @param array{imported: int, updated: int, errors: list<array<string, mixed>>} $report
     */
    public function markCompleted(string $jobId, array $report): void
    {
        $this->patch($jobId, [
            'status' => self::STATUS_COMPLETED,
            'imported' => $report['imported'],
            'updated' => $report['updated'],
            'errors' => $report['errors'],
            'finishedAt' => $this->now(),
        ]);
    }

    public function markFailed(string $jobId, string $error): void
    {
        $this->patch($jobId, [
            'status' => self::STATUS_FAILED,
            'error' => $error,
            'finishedAt' => $this->now(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $jobId): ?array
    {
        $file = $this->file($jobId);

        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function patch(string $jobId, array $patch): void
    {
        $current = $this->read($jobId) ?? [
            'status' => self::STATUS_QUEUED,
            'imported' => 0,
            'updated' => 0,
            'errors' => [],
        ];

        $this->write($jobId, array_merge($current, $patch));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function write(string $jobId, array $payload): void
    {
        $dir = $this->dir();

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create import status dir "%s"', $dir));
        }

        $payload['jobId'] = $jobId;
        $payload['updatedAt'] = $this->now();

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $file = $this->file($jobId);
        $tmp = $file . '.tmp';

        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Cannot write import status file "%s"', $tmp));
        }

        if (!rename($tmp, $file)) {
            @unlink($tmp);

            throw new RuntimeException(sprintf('Cannot replace import status file "%s"', $file));
        }
    }

    private function file(string $jobId): string
    {
        if (!self::isValidJobId($jobId)) {
            throw new RuntimeException('invalid job id');
        }

        return $this->dir() . '/' . $jobId . '.json';
    }

    private function dir(): string
    {
        return (string) $this->settings->get('import_status_dir', dirname(__DIR__, 2) . '/var/imports');
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }
}
