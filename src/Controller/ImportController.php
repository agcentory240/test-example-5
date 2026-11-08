<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ImportFileStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

final class ImportController
{
    private const ALLOWED_EXTENSION = 'xlsx';

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
        private readonly LoggerInterface $logger,
    ) {}

    public function upload(Request $request, Response $response): Response
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
            'size' => $file->getSize(),
            'mime' => $mimeType,
        ]);

        // Заглушка: сообщение в очередь (Messenger) будет диспатчиться следующим шагом.
        return $this->json($response, [
            'status' => 'ok',
            'jobId' => $jobId,
            'filename' => $originalName,
            'size' => $file->getSize(),
        ], 202);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(Response $response, array $payload, int $status): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
