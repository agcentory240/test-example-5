<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ImportProductsMessage;
use App\Repository\ProductImageRepository;
use App\Service\ImageDownloaderService;
use App\Service\ImportJobStatusStorage;
use App\Service\ProductImportService;
use App\Service\XlsxParserService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Обработчик импорта: XLSX → DTO → БД → скачивание изображений → статус задачи.
 * Ошибка одной картинки не ломает импорт: попадает в errors, товар сохраняется.
 */
final class ImportProductsHandler
{
    public function __construct(
        private readonly XlsxParserService $parser,
        private readonly ProductImportService $importer,
        private readonly ProductImageRepository $images,
        private readonly ImageDownloaderService $downloader,
        private readonly ImportJobStatusStorage $statusStorage,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ImportProductsMessage $message): void
    {
        $this->statusStorage->markProcessing($message->jobId);
        $this->logger->info('Import job started', [
            'jobId' => $message->jobId,
            'file' => $message->filePath,
        ]);

        try {
            $dtos = $this->parser->parse($message->filePath);
            $report = $this->importer->import($dtos);
            $imageErrors = $this->downloadImages();
        } catch (\Throwable $e) {
            $this->logger->error('Import job failed', [
                'jobId' => $message->jobId,
                'exception' => $e,
            ]);

            $this->statusStorage->markFailed($message->jobId, $e->getMessage());

            return;
        }

        $errors = array_merge($report['errors'], $imageErrors);

        $this->statusStorage->markCompleted($message->jobId, [
            'imported' => $report['imported'],
            'updated' => $report['updated'],
            'errors' => $errors,
        ]);

        $this->logger->info('Import job completed', [
            'jobId' => $message->jobId,
            'imported' => $report['imported'],
            'updated' => $report['updated'],
            'errors' => count($errors),
        ]);
    }

    /**
     * Скачивает все изображения с path = null и проставляет локальный путь.
     *
     * @return list<array<string, mixed>> ошибки скачивания
     */
    private function downloadImages(): array
    {
        $errors = [];

        foreach ($this->images->findWithoutPath() as $image) {
            $productId = $image->getProduct()->getId();

            if ($productId === null) {
                continue;
            }

            try {
                $image->setPath($this->downloader->download($image->getUrl(), $productId));
            } catch (\Throwable $e) {
                $this->logger->warning('Cannot download product image', [
                    'productId' => $productId,
                    'url' => $image->getUrl(),
                    'exception' => $e,
                ]);

                $errors[] = [
                    'type' => 'image',
                    'productId' => $productId,
                    'url' => $image->getUrl(),
                    'message' => $e->getMessage(),
                ];
            }
        }

        $this->em->flush();

        return $errors;
    }
}
