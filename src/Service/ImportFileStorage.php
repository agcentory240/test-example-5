<?php

declare(strict_types=1);

namespace App\Service;

use App\Settings\SettingsInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Хранение загруженных файлов импорта (var/uploads/imports/{jobId}.xlsx).
 */
final class ImportFileStorage
{
    private const DEFAULT_MAX_FILE_SIZE = 10 * 1024 * 1024;

    public function __construct(private readonly SettingsInterface $settings) {}

    public function getMaxFileSize(): int
    {
        return (int) $this->settings->get('import_max_file_size', self::DEFAULT_MAX_FILE_SIZE);
    }

    public function getUploadDir(): string
    {
        return (string) $this->settings->get('import_upload_dir', dirname(__DIR__, 2) . '/var/uploads/imports');
    }

    /**
     * UUID v4 без внешних зависимостей (Ramsey\Uuid не подключён).
     */
    public function generateJobId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Перемещает загруженный файл в каталог импорта и возвращает путь к файлу.
     */
    public function save(UploadedFileInterface $file, string $jobId): string
    {
        $dir = $this->getUploadDir();

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create upload dir "%s"', $dir));
        }

        $path = $dir . '/' . $jobId . '.xlsx';
        $file->moveTo($path);

        return $path;
    }
}
