<?php

declare(strict_types=1);

namespace App\Service;

use App\Settings\SettingsInterface;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Скачивание изображений товаров в public/uploads/products/{productId}/{hash}.{ext}.
 * Возвращает относительный путь, который пишется в product_images.path.
 */
final class ImageDownloaderService
{
    private const MAX_BYTES = 15 * 1024 * 1024;

    private const URL_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'avif', 'svg'];

    private readonly HttpClientInterface $client;

    private readonly string $publicDir;

    public function __construct(SettingsInterface $settings)
    {
        $this->client = HttpClient::create([
            'timeout' => 15,
            'max_duration' => 30,
            'max_redirects' => 5,
            'headers' => ['User-Agent' => 'ProductImport/1.0'],
        ]);

        $this->publicDir = rtrim((string) $settings->get(
            'import_public_dir',
            dirname(__DIR__, 2) . '/public',
        ), '/');
    }

    /**
     * @return string относительный путь от public/ (например, uploads/products/1/abc.jpg)
     */
    public function download(string $url, int $productId): string
    {
        $response = $this->client->request('GET', $url);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('HTTP %d for "%s"', $status, $url));
        }

        $content = $response->getContent();

        if ($content === '') {
            throw new RuntimeException('Empty image body');
        }

        if (strlen($content) > self::MAX_BYTES) {
            throw new RuntimeException('Image too large');
        }

        $extension = $this->detectExtension($content, $this->contentType($response), $url);

        $dir = sprintf('%s/uploads/products/%d', $this->publicDir, $productId);

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create image dir "%s"', $dir));
        }

        $filename = sha1($url) . '.' . $extension;

        if (file_put_contents($dir . '/' . $filename, $content) === false) {
            throw new RuntimeException(sprintf('Cannot write image "%s"', $filename));
        }

        return sprintf('uploads/products/%d/%s', $productId, $filename);
    }

    private function contentType(ResponseInterface $response): string
    {
        $headers = $response->getHeaders(false);
        $values = $headers['content-type'] ?? [];
        $value = $values[0] ?? '';

        return strtolower(trim(explode(';', $value)[0]));
    }

    /**
     * Проверяем реальное содержимое: сервера часто отдают jpg как binary/octet-stream.
     */
    private function detectExtension(string $content, string $contentType, string $url): string
    {
        $info = @getimagesizefromstring($content);

        if (is_array($info)) {
            $byType = [
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_PNG => 'png',
                IMAGETYPE_GIF => 'gif',
                IMAGETYPE_WEBP => 'webp',
                IMAGETYPE_BMP => 'bmp',
                IMAGETYPE_AVIF => 'avif',
            ];

            if (isset($byType[$info[2]])) {
                return $byType[$info[2]];
            }
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, self::URL_EXTENSIONS, true)) {
            return $extension === 'jpeg' ? 'jpg' : $extension;
        }

        throw new RuntimeException(sprintf(
            'Unsupported image "%s" (content type "%s")',
            $url,
            $contentType,
        ));
    }
}
