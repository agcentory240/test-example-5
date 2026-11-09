<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Entity\ProductAttribute;
use App\Entity\ProductImage;
use App\Settings\SettingsInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Тестовая очистка каталога: удаляет все товары (с атрибутами и изображениями)
 * в одной транзакции и стирает файлы public/uploads/products/.
 */
final class ProductCleanupService
{
    private readonly string $uploadsDir;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SettingsInterface $settings,
        private readonly LoggerInterface $logger,
    ) {
        $this->uploadsDir = rtrim((string) $settings->get(
            'import_public_dir',
            dirname(__DIR__, 2) . '/public',
        ), '/') . '/uploads/products';
    }

    public function clearAll(): void
    {
        $this->em->wrapInTransaction(function (): void {
            // Порядок важен: сначала зависимые таблицы, затем товары.
            $this->em->createQuery(sprintf('DELETE FROM %s i', ProductImage::class))->execute();
            $this->em->createQuery(sprintf('DELETE FROM %s a', ProductAttribute::class))->execute();
            $this->em->createQuery(sprintf('DELETE FROM %s p', Product::class))->execute();
        });

        if (is_dir($this->uploadsDir)) {
            $this->removeDirectory($this->uploadsDir);
        }

        $this->logger->info('Products and uploaded files cleared', ['dir' => $this->uploadsDir]);
    }

    private function removeDirectory(string $dir): void
    {
        $items = scandir($dir);

        if ($items === false) {
            throw new RuntimeException(sprintf('Cannot read directory "%s"', $dir));
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            if (!unlink($path)) {
                throw new RuntimeException(sprintf('Cannot delete file "%s"', $path));
            }
        }

        if (!rmdir($dir)) {
            throw new RuntimeException(sprintf('Cannot delete directory "%s"', $dir));
        }
    }
}
