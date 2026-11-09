<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ProductDTO;
use App\Message\ImportProductsMessage;
use App\Service\ImportFileStorage;
use App\Service\ImportJobStatusStorage;
use App\Service\XlsxParserService;
use OpenApi\Spec as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

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
        private readonly ImportJobStatusStorage $statusStorage,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    #[OA\Operation\Post(
        path: '/api/import',
        summary: 'Загрузить XLSX с товарами (асинхронный импорт)',
        description: 'Файл сохраняется, сообщение уходит в RabbitMQ. Лимит: 10 запросов в час с одного IP.',
        tags: ['Import'],
        parameters: [
            new OA\Parameter(
                name: 'file',
                in: 'query',
                description: 'XLSX-файл (multipart/form-data, поле "file", до 10 МБ)',
                schema: new OA\Schema(type: 'string', format: 'binary'),
            ),
        ],
        responses: [
            new OA\Response(response: 202, description: 'Импорт поставлен в очередь: {status, jobId}'),
            new OA\Response(response: 400, description: 'Файл не передан или неверный тип'),
            new OA\Response(response: 429, description: 'Превышен лимит импортов'),
        ],
    )]
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

        $this->statusStorage->markQueued($jobId);

        // Импорт асинхронный: парсинг, запись в БД и скачивание картинок делает воркер.
        try {
            $this->bus->dispatch(new ImportProductsMessage($jobId, $path));
        } catch (\Throwable $e) {
            $this->logger->error('Cannot dispatch import job', ['jobId' => $jobId, 'exception' => $e]);
            $this->statusStorage->markFailed($jobId, 'cannot dispatch job');

            return $this->json($response, ['error' => 'cannot dispatch job'], 500);
        }

        return $this->json($response, ['status' => 'queued', 'jobId' => $jobId], 202);
    }

    /**
     * Статус задачи импорта: читает var/imports/{jobId}.json.
     *
     * @param array<string, string> $args
     */
    public function status(Request $request, Response $response, array $args): Response
    {
        $jobId = (string) ($args['jobId'] ?? '');

        if (!ImportJobStatusStorage::isValidJobId($jobId)) {
            return $this->json($response, ['error' => 'invalid job id'], 400);
        }

        $state = $this->statusStorage->read($jobId);

        if ($state === null) {
            return $this->json($response, ['error' => 'job not found'], 404);
        }

        return $this->json($response, [
            'jobId' => $jobId,
            'status' => $state['status'] ?? 'unknown',
            'imported' => (int) ($state['imported'] ?? 0),
            'updated' => (int) ($state['updated'] ?? 0),
            'errors' => $state['errors'] ?? [],
            'error' => $state['error'] ?? null,
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
