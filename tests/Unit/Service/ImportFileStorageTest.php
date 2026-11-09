<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\ImportFileStorage;
use App\Settings\Settings;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\UploadedFile;
use Tests\Support\TempDir;

final class ImportFileStorageTest extends TestCase
{
    private string $dir;

    private ImportFileStorage $storage;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('import_files_');
        $this->storage = new ImportFileStorage(new Settings([
            'import_upload_dir' => $this->dir . '/uploads',
            'import_max_file_size' => 1024,
        ]));
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testGenerateJobIdReturnsUuidV4(): void
    {
        $jobId = $this->storage->generateJobId();
        $other = $this->storage->generateJobId();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $jobId,
        );
        self::assertNotSame($jobId, $other);
    }

    public function testSettingsAreExposed(): void
    {
        self::assertSame(1024, $this->storage->getMaxFileSize());
        self::assertSame($this->dir . '/uploads', $this->storage->getUploadDir());
    }

    public function testSaveMovesUploadedFileToUploadDir(): void
    {
        $source = $this->dir . '/source.xlsx';
        file_put_contents($source, 'xlsx-content');

        $file = new UploadedFile($source, 'products.xlsx', 'application/octet-stream', 12);
        $jobId = '11111111-2222-4333-8444-555555555555';

        $path = $this->storage->save($file, $jobId);

        self::assertSame($this->dir . '/uploads/' . $jobId . '.xlsx', $path);
        self::assertFileExists($path);
        self::assertSame('xlsx-content', file_get_contents($path));
        self::assertFileDoesNotExist($source);
    }
}
