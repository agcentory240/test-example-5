<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Controller\ImportController;
use App\Service\ImportFileStorage;
use App\Service\ImportJobStatusStorage;
use App\Service\XlsxParserService;
use App\Settings\Settings;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Tests\Support\TempDir;

final class ImportControllerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures';

    private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const JOB_ID = '11111111-2222-4333-8444-555555555555';

    private string $dir;

    private Settings $settings;

    private ImportJobStatusStorage $statusStorage;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('import_controller_');
        $this->settings = new Settings([
            'import_upload_dir' => $this->dir . '/uploads/imports',
            'import_max_file_size' => 10 * 1024 * 1024,
            'import_status_dir' => $this->dir . '/imports',
        ]);
        $this->statusStorage = new ImportJobStatusStorage($this->settings);
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    private function controller(?MessageBusInterface $bus = null): ImportController
    {
        return new ImportController(
            new ImportFileStorage($this->settings),
            new XlsxParserService(),
            $this->statusStorage,
            $bus ?? $this->bus(),
            new NullLogger(),
        );
    }

    private function bus(): MessageBusInterface
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static fn(object $message): Envelope => new Envelope($message),
        );

        return $bus;
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * Копирует фикстуру во временный файл: moveTo() переносит, а не копирует.
     */
    private function uploadedFile(string $clientName = 'products.xlsx', string $mime = self::MIME): UploadedFile
    {
        $path = $this->dir . '/upload-' . bin2hex(random_bytes(4)) . '.xlsx';
        copy(self::FIXTURES . '/products.xlsx', $path);

        return new UploadedFile($path, $clientName, $mime, (int) filesize($path));
    }

    private function uploadRequest(?UploadedFile $file): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/import');

        return $file === null ? $request : $request->withUploadedFiles(['file' => $file]);
    }

    public function testUploadReturns400WithoutFile(): void
    {
        $response = $this->controller()->upload($this->uploadRequest(null), $this->response());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'file is required'], $this->decode($response));
    }

    public function testUploadRejectsInvalidExtension(): void
    {
        $response = $this->controller()->upload(
            $this->uploadRequest($this->uploadedFile('products.csv')),
            $this->response(),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'invalid file type'], $this->decode($response));
    }

    public function testUploadRejectsInvalidMimeType(): void
    {
        $response = $this->controller()->upload(
            $this->uploadRequest($this->uploadedFile('products.xlsx', 'text/plain')),
            $this->response(),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'invalid file type'], $this->decode($response));
    }

    public function testUploadStoresFileQueuesJobAndMarksQueued(): void
    {
        $response = $this->controller()->upload(
            $this->uploadRequest($this->uploadedFile()),
            $this->response(),
        );

        self::assertSame(202, $response->getStatusCode());

        $payload = $this->decode($response);

        self::assertSame('queued', $payload['status']);
        self::assertIsString($payload['jobId']);
        self::assertTrue(ImportJobStatusStorage::isValidJobId($payload['jobId']));
        self::assertFileExists($this->settings->get('import_upload_dir') . '/' . $payload['jobId'] . '.xlsx');

        $state = $this->statusStorage->read($payload['jobId']);
        self::assertSame(ImportJobStatusStorage::STATUS_QUEUED, $state['status']);
    }

    public function testUploadReturns500AndMarksFailedWhenDispatchFails(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new RuntimeException('rabbit is down'));

        $response = $this->controller($bus)->upload(
            $this->uploadRequest($this->uploadedFile()),
            $this->response(),
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'cannot dispatch job'], $this->decode($response));

        $files = glob($this->settings->get('import_upload_dir') . '/*.xlsx');
        self::assertIsArray($files);
        self::assertCount(1, $files);

        $jobId = basename($files[0], '.xlsx');
        $state = $this->statusStorage->read($jobId);
        self::assertSame(ImportJobStatusStorage::STATUS_FAILED, $state['status']);
        self::assertSame('cannot dispatch job', $state['error']);
    }

    public function testStatusReturns400ForInvalidJobId(): void
    {
        $response = $this->controller()->status(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/import/not-a-uuid/status'),
            $this->response(),
            ['jobId' => 'not-a-uuid'],
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'invalid job id'], $this->decode($response));
    }

    public function testStatusReturns404ForUnknownJob(): void
    {
        $response = $this->controller()->status(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/import/status'),
            $this->response(),
            ['jobId' => self::JOB_ID],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'job not found'], $this->decode($response));
    }

    public function testStatusReturnsJobState(): void
    {
        $this->statusStorage->markQueued(self::JOB_ID);
        $this->statusStorage->markCompleted(self::JOB_ID, ['imported' => 1, 'updated' => 0, 'errors' => []]);

        $response = $this->controller()->status(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/import/status'),
            $this->response(),
            ['jobId' => self::JOB_ID],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'jobId' => self::JOB_ID,
            'status' => ImportJobStatusStorage::STATUS_COMPLETED,
            'imported' => 1,
            'updated' => 0,
            'errors' => [],
            'error' => null,
        ], $this->decode($response));
    }

    public function testStatusReturnsFailureReason(): void
    {
        $this->statusStorage->markQueued(self::JOB_ID);
        $this->statusStorage->markFailed(self::JOB_ID, 'xlsx is broken');

        $response = $this->controller()->status(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/import/status'),
            $this->response(),
            ['jobId' => self::JOB_ID],
        );

        self::assertSame(200, $response->getStatusCode());

        $payload = $this->decode($response);

        self::assertSame(ImportJobStatusStorage::STATUS_FAILED, $payload['status']);
        self::assertSame('xlsx is broken', $payload['error']);
    }

    public function testParseTestReturnsPreview(): void
    {
        $response = $this->controller()->parseTest(
            $this->uploadRequest($this->uploadedFile()),
            $this->response(),
        );

        self::assertSame(200, $response->getStatusCode());

        $payload = $this->decode($response);

        self::assertCount(3, $payload);
        self::assertSame('A-001', $payload[0]['externalCode']);
        self::assertSame(['Бренд' => 'Zara', 'Размер' => '46(M)'], $payload[0]['attributes']);
        self::assertCount(2, $payload[0]['imageUrls']);
    }
}
