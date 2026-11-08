<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Задание на асинхронный импорт товаров из XLSX.
 * Содержит идентификатор задачи (для статуса) и путь к загруженному файлу.
 */
final class ImportProductsMessage
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $filePath,
    ) {}
}
