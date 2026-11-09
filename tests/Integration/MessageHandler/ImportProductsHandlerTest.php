<?php

declare(strict_types=1);

namespace Tests\Integration\MessageHandler;

use App\Message\ImportProductsMessage;
use App\MessageHandler\ImportProductsHandler;
use App\Repository\ProductImageRepository;
use App\Repository\ProductRepository;
use App\Service\ImageDownloaderService;
use App\Service\ImportJobStatusStorage;
use App\Service\ProductImportService;
use App\Service\XlsxParserService;
use App\Settings\Settings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\DoctrineTestHelper;
use Tests\Support\TempDir;

final class ImportProductsHandlerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures';

    private const JOB_ID = '11111111-2222-4333-8444-555555555555';

    private string $dir;

    private ImportJobStatusStorage $statusStorage;

    private ProductRepository $products;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('import_handler_');
        $settings = new Settings([
            'import_status_dir' => $this->dir . '/status',
            'import_public_dir' => $this->dir . '/public',
        ]);

        $this->em = DoctrineTestHelper::createEntityManager();
        $this->products = new ProductRepository($this->em);
        $this->statusStorage = new ImportJobStatusStorage($settings);

        $this->statusStorage->markQueued(self::JOB_ID);

        $this->handler = new ImportProductsHandler(
            new XlsxParserService(),
            new ProductImportService($this->em, $this->products, new NullLogger()),
            new ProductImageRepository($this->em),
            new ImageDownloaderService($settings),
            $this->statusStorage,
            $this->em,
            new NullLogger(),
        );
    }

    private ImportProductsHandler $handler;

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    public function testHandlerImportsProductAndMarksJobCompleted(): void
    {
        ($this->handler)(new ImportProductsMessage(
            self::JOB_ID,
            self::FIXTURES . '/products-no-images.xlsx',
        ));

        $state = $this->statusStorage->read(self::JOB_ID);

        self::assertSame(ImportJobStatusStorage::STATUS_COMPLETED, $state['status']);
        self::assertSame(1, $state['imported']);
        self::assertSame(0, $state['updated']);
        self::assertSame([], $state['errors']);
        self::assertArrayHasKey('startedAt', $state);
        self::assertArrayHasKey('finishedAt', $state);

        $this->em->clear();
        self::assertNotNull($this->products->findByExternalCode('A-010'));
    }

    public function testHandlerCollectsRowErrorsAndCompletesJob(): void
    {
        ($this->handler)(new ImportProductsMessage(
            self::JOB_ID,
            self::FIXTURES . '/products-errors.xlsx',
        ));

        $state = $this->statusStorage->read(self::JOB_ID);

        self::assertSame(ImportJobStatusStorage::STATUS_COMPLETED, $state['status']);
        self::assertSame(1, $state['imported']);
        self::assertCount(1, $state['errors']);
        self::assertSame('A-101', $state['errors'][0]['externalCode']);
        self::assertSame(3, $state['errors'][0]['row']);
    }

    public function testHandlerMarksJobFailedWhenFileIsMissing(): void
    {
        ($this->handler)(new ImportProductsMessage(
            self::JOB_ID,
            self::FIXTURES . '/missing.xlsx',
        ));

        $state = $this->statusStorage->read(self::JOB_ID);

        self::assertSame(ImportJobStatusStorage::STATUS_FAILED, $state['status']);
        self::assertStringContainsString('not found', $state['error']);
    }
}
