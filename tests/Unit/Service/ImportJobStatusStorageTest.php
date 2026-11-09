<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\ImportJobStatusStorage;
use App\Settings\Settings;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TempDir;

final class ImportJobStatusStorageTest extends TestCase
{
    private const JOB_ID = '11111111-2222-4333-8444-555555555555';

    private string $dir;

    private ImportJobStatusStorage $storage;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('import_status_');
        $this->storage = new ImportJobStatusStorage(new Settings(['import_status_dir' => $this->dir]));
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testIsValidJobId(): void
    {
        self::assertTrue(ImportJobStatusStorage::isValidJobId(self::JOB_ID));
        self::assertTrue(ImportJobStatusStorage::isValidJobId(strtoupper(self::JOB_ID)));
        self::assertFalse(ImportJobStatusStorage::isValidJobId('not-a-uuid'));
        self::assertFalse(ImportJobStatusStorage::isValidJobId('../etc/passwd'));
        self::assertFalse(ImportJobStatusStorage::isValidJobId(''));
    }

    public function testMarkQueuedWritesInitialStatus(): void
    {
        $this->storage->markQueued(self::JOB_ID);

        $state = $this->storage->read(self::JOB_ID);

        self::assertIsArray($state);
        self::assertSame(self::JOB_ID, $state['jobId']);
        self::assertSame(ImportJobStatusStorage::STATUS_QUEUED, $state['status']);
        self::assertSame(0, $state['imported']);
        self::assertSame(0, $state['updated']);
        self::assertSame([], $state['errors']);
        self::assertArrayHasKey('createdAt', $state);
    }

    public function testMarkProcessingDoesNotResetCounters(): void
    {
        $this->storage->markQueued(self::JOB_ID);
        $this->storage->markProcessing(self::JOB_ID);

        $state = $this->storage->read(self::JOB_ID);

        self::assertSame(ImportJobStatusStorage::STATUS_PROCESSING, $state['status']);
        self::assertArrayHasKey('startedAt', $state);
        self::assertSame(0, $state['imported']);
    }

    public function testMarkCompletedStoresReport(): void
    {
        $this->storage->markQueued(self::JOB_ID);
        $this->storage->markCompleted(self::JOB_ID, [
            'imported' => 2,
            'updated' => 1,
            'errors' => [['row' => 4, 'externalCode' => 'A-4', 'message' => 'name is empty']],
        ]);

        $state = $this->storage->read(self::JOB_ID);

        self::assertSame(ImportJobStatusStorage::STATUS_COMPLETED, $state['status']);
        self::assertSame(2, $state['imported']);
        self::assertSame(1, $state['updated']);
        self::assertCount(1, $state['errors']);
        self::assertArrayHasKey('finishedAt', $state);
    }

    public function testMarkFailedStoresError(): void
    {
        $this->storage->markQueued(self::JOB_ID);
        $this->storage->markFailed(self::JOB_ID, 'file not found');

        $state = $this->storage->read(self::JOB_ID);

        self::assertSame(ImportJobStatusStorage::STATUS_FAILED, $state['status']);
        self::assertSame('file not found', $state['error']);
    }

    public function testReadReturnsNullForUnknownJob(): void
    {
        self::assertNull($this->storage->read('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'));
    }

    public function testInvalidJobIdIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid job id');

        $this->storage->markQueued('../../etc/passwd');
    }
}
