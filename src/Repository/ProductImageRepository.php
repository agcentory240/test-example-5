<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductImage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Репозиторий изображений товаров: только запросы к БД.
 */
final class ProductImageRepository
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Изображения, которые ещё не скачаны (path = null).
     *
     * @return ProductImage[]
     */
    public function findWithoutPath(): array
    {
        /** @var ProductImage[] $images */
        $images = $this->em->getRepository(ProductImage::class)->findBy(['path' => null]);

        return $images;
    }
}
