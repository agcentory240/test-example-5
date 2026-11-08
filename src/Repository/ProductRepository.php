<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Репозиторий товаров: только запросы к БД, без бизнес-логики.
 */
final class ProductRepository
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function findByExternalCode(string $externalCode): ?Product
    {
        /** @var Product|null $product */
        $product = $this->em->getRepository(Product::class)->findOneBy(['externalCode' => $externalCode]);

        return $product;
    }
}
