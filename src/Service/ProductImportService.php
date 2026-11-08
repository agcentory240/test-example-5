<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ProductDTO;
use App\Entity\Product;
use App\Entity\ProductAttribute;
use App\Entity\ProductImage;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Запись распарсенных DTO в БД: upsert по externalCode.
 * Транзакция — на каждый товар: ошибка одной строки не ломает остальные.
 */
final class ProductImportService
{
    private readonly string $projectRoot;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductRepository $products,
        private readonly LoggerInterface $logger,
    ) {
        $this->projectRoot = dirname(__DIR__, 2);
    }

    /**
     * @param ProductDTO[] $dtos
     *
     * @return array{
     *     imported: int,
     *     updated: int,
     *     errors: list<array{row: int, externalCode: string, message: string}>
     * }
     */
    public function import(array $dtos): array
    {
        $report = ['imported' => 0, 'updated' => 0, 'errors' => []];

        foreach ($dtos as $dto) {
            try {
                $isNew = $this->saveProduct($dto);
            } catch (\Throwable $e) {
                $this->logger->error('Import product failed', [
                    'row' => $dto->sourceRow,
                    'exception' => $e,
                ]);

                $report['errors'][] = [
                    'row' => $dto->sourceRow,
                    'externalCode' => $dto->externalCode,
                    'message' => $e->getMessage(),
                ];

                continue;
            }

            if ($isNew) {
                ++$report['imported'];
            } else {
                ++$report['updated'];
            }
        }

        return $report;
    }

    /**
     * Один товар + атрибуты + изображения в одной транзакции.
     *
     * @return bool true — создан новый, false — обновлён существующий
     */
    private function saveProduct(ProductDTO $dto): bool
    {
        $this->validate($dto);

        try {
            /** @var bool $isNew */
            $isNew = $this->em->wrapInTransaction(function () use ($dto): bool {
                $product = $this->products->findByExternalCode($dto->externalCode);
                $isNew = $product === null;

                if ($product === null) {
                    $product = (new Product())->setExternalCode($dto->externalCode);
                }

                $product
                    ->setName($dto->name)
                    ->setUuid($dto->uuid)
                    ->setDescription($dto->description)
                    ->setPrice($this->decimal($dto->price))
                    ->setPurchasePrice($dto->purchasePrice === null ? null : $this->decimal($dto->purchasePrice));

                $this->syncAttributes($product, $dto->attributes);
                $this->syncImages($product, $dto->imageUrls);

                $this->em->persist($product);

                return $isNew;
            });
        } catch (\Throwable $e) {
            // После отката EM должен быть очищен, иначе останется «грязное» состояние.
            $this->em->clear();

            throw $e;
        }

        return $isNew;
    }

    private function validate(ProductDTO $dto): void
    {
        if ($dto->externalCode === '') {
            throw new RuntimeException('external code is empty');
        }

        if ($dto->name === '') {
            throw new RuntimeException('name is empty');
        }

        if ($dto->price < 0) {
            throw new RuntimeException('price is negative');
        }
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * Сравнение по key: существующие значения обновляются, новые добавляются,
     * отсутствующие в DTO — удаляются (orphanRemoval).
     *
     * @param array<string, string> $attributes
     */
    private function syncAttributes(Product $product, array $attributes): void
    {
        /** @var array<string, list<ProductAttribute>> $existing */
        $existing = [];

        foreach ($product->getAttributes() as $attribute) {
            $existing[$attribute->getKey()][] = $attribute;
        }

        foreach ($attributes as $key => $value) {
            if (($existing[$key] ?? []) !== []) {
                $attribute = array_shift($existing[$key]);
                $attribute->setValue($value);

                if ($existing[$key] === []) {
                    unset($existing[$key]);
                }

                continue;
            }

            $product->addAttribute((new ProductAttribute())->setKey($key)->setValue($value));
        }

        foreach ($existing as $obsoleteAttributes) {
            foreach ($obsoleteAttributes as $attribute) {
                $product->removeAttribute($attribute);
            }
        }
    }

    /**
     * Сравнение по url: существующие записи не трогаем (path сохраняется),
     * новые добавляем, отсутствующие в DTO — удаляем вместе с файлом.
     *
     * @param string[] $urls
     */
    private function syncImages(Product $product, array $urls): void
    {
        /** @var array<string, list<ProductImage>> $existing */
        $existing = [];

        foreach ($product->getImages() as $image) {
            $existing[$image->getUrl()][] = $image;
        }

        foreach (array_unique($urls) as $url) {
            if (($existing[$url] ?? []) !== []) {
                array_shift($existing[$url]);

                if ($existing[$url] === []) {
                    unset($existing[$url]);
                }

                continue;
            }

            $product->addImage((new ProductImage())->setUrl($url));
        }

        foreach ($existing as $obsoleteImages) {
            foreach ($obsoleteImages as $image) {
                $this->deleteImageFile($image);
                $product->removeImage($image);
            }
        }
    }

    /**
     * Удаляет локальный файл изображения, если он был скачан.
     * Ошибка удаления не должна прерывать импорт товара.
     */
    private function deleteImageFile(ProductImage $image): void
    {
        $path = $image->getPath();

        if ($path === null || $path === '') {
            return;
        }

        $file = str_starts_with($path, '/') ? $path : $this->projectRoot . '/' . ltrim($path, '/');

        if (!is_file($file)) {
            return;
        }

        if (!@unlink($file)) {
            $this->logger->warning('Cannot delete product image file', ['file' => $file]);
        }
    }
}
