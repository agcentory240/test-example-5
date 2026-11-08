<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ProductDTO;
use App\Service\ImportFileStorage;
use App\Service\ProductImportService;
use App\Service\XlsxParserService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

final class ImportController
{
    private const ALLOWED_EXTENSION = 'xlsx';

    private const PARSE_PREVIEW_LIMIT = 3;

    /**
     * ВАЖНО: MIME проверяем по белому списку, но не требуем строго —
     * curl/браузер может отдать application/octet-stream.
     */
    private const ALLOWED_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/zip',
        'application/octet-stream',
    ];

    public function __construct(
        private readonly ImportFileStorage $storage,
        private readonly XlsxParserService $parser,
        private readonly ProductImportService $importer,
        private readonly LoggerInterface $logger,
    ) {}

    public function upload(Request $request, Response $response): Response
    {
        $file = $this->extractFile($request, $response);

        if ($file instanceof Response) {
            return $file;
        }

        $originalName = (string) $file->getClientFilename();
        $mimeType = strtolower((string) $file->getClientMediaType());
        $jobId = $this->storage->generateJobId();

        try {
            $path = $this->storage->save($file, $jobId);
        } catch (\Throwable $e) {
            $this->logger->error('Cannot store import file', ['jobId' => $jobId, 'exception' => $e]);

            return $this->json($response, ['error' => 'cannot store file'], 500);
        }

        $this->logger->info('Import file accepted', [
            'jobId' => $jobId,
            'path' => $path,
            'filename' => $originalName,
            'size' => $file->getSize(),
            'mime' => $mimeType,
        ]);

        // Синхронный импорт: парсинг + запись в БД. Очередь (Messenger) — следующий шаг.
        try {
            $products = $this->parser->parse($path);
        } catch (\Throwable $e) {
            $this->logger->error('XLSX parse failed', ['jobId' => $jobId, 'exception' => $e]);

            return $this->json($response, ['status' => 'error', 'error' => 'cannot parse file'], 422);
        }

        $report = $this->importer->import($products);

        return $this->json($response, [
            'status' => 'ok',
            'imported' => $report['imported'],
            'updated' => $report['updated'],
            'errors' => $report['errors'],
        ], 200);
    }

    /**
     * Временный эндпоинт: только парсинг XLSX в DTO, без записи в БД и очереди.
     */
    public function parseTest(Request $request, Response $response): Response
    {
        $file = $this->extractFile($request, $response);

        if ($file instanceof Response) {
            return $file;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'import_parse');

        if ($tmpPath === false) {
            $this->logger->error('Cannot create temp file for parse test');

            return $this->json($response, ['error' => 'cannot create temp file'], 500);
        }

        try {
            $file->moveTo($tmpPath);
            $products = $this->parser->parse($tmpPath);
        } catch (\Throwable $e) {
            $this->logger->error('XLSX parse test failed', ['exception' => $e]);

            return $this->json($response, ['error' => 'cannot parse file'], 422);
        } finally {
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }
        }

        $preview = array_map(
            static fn(ProductDTO $product): array => $product->toArray(),
            array_slice($products, 0, self::PARSE_PREVIEW_LIMIT),
        );

        return $this->json($response, $preview, 200);
    }

    /**
     * Валидация загруженного файла: наличие, ошибки загрузки, размер, расширение, MIME.
     */
    private function extractFile(Request $request, Response $response): UploadedFileInterface|Response
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            return $this->json($response, ['error' => 'file is required'], 400);
        }

        $error = $file->getError();

        if ($error === UPLOAD_ERR_NO_FILE) {
            return $this->json($response, ['error' => 'file is required'], 400);
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return $this->json($response, ['error' => 'file too large'], 400);
        }

        if ($error !== UPLOAD_ERR_OK) {
            $this->logger->warning('Import upload failed', ['upload_error' => $error]);

            return $this->json($response, ['error' => 'file upload failed'], 400);
        }

        if ($file->getSize() === null || $file->getSize() > $this->storage->getMaxFileSize()) {
            return $this->json($response, ['error' => 'file too large'], 400);
        }

        $originalName = (string) $file->getClientFilename();
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mimeType = strtolower((string) $file->getClientMediaType());

        if ($extension !== self::ALLOWED_EXTENSION
            || ($mimeType !== '' && !in_array($mimeType, self::ALLOWED_MIME_TYPES, true))
        ) {
            return $this->json($response, ['error' => 'invalid file type'], 400);
        }

        return $file;
    }

    /**
     * @param array<mixed> $payload
     */
    private function json(Response $response, array $payload, int $status): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
