<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\ProductImage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Репозиторий товаров: только запросы к БД, без бизнес-логики.
 */
final class ProductRepository
{
    private const ALLOWED_SORTS = ['id', 'name'];

    public function __construct(private readonly EntityManagerInterface $em) {}

    public function findByExternalCode(string $externalCode): ?Product
    {
        /** @var Product|null $product */
        $product = $this->em->getRepository(Product::class)->findOneBy(['externalCode' => $externalCode]);

        return $product;
    }

    /**
     * Серверная пагинация с фильтрами: поиск по подстроке названия (ILIKE),
     * диапазон цены, сортировка по id или name (ASC).
     *
     * @return array{items: list<Product>, total: int}
     */
    public function paginate(
        ?string $name,
        ?float $priceMin,
        ?float $priceMax,
        int $page,
        int $limit,
        string $sort = 'id',
    ): array {
        $qb = $this->baseQuery();
        $this->applyFilters($qb, $name, $priceMin, $priceMax);

        $total = (int) (clone $qb)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $sortField = in_array($sort, self::ALLOWED_SORTS, true) ? $sort : 'id';
        $qb
            ->orderBy('p.' . $sortField, 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        /** @var list<Product> $items */
        $items = $qb->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Товар с атрибутами и изображениями (карточка).
     */
    public function findWithDetails(int $id): ?Product
    {
        /** @var Product|null $product */
        $product = $this->em
            ->createQueryBuilder()
            ->select('p', 'a', 'i')
            ->from(Product::class, 'p')
            ->leftJoin('p.attributes', 'a')
            ->leftJoin('p.images', 'i')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $product;
    }

    /**
     * Изображения для набора товаров одним запросом (без N+1 в списке).
     *
     * @param list<int> $productIds
     *
     * @return array<int, list<ProductImage>>
     */
    public function findImagesByProductIds(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        /** @var list<ProductImage> $images */
        $images = $this->em
            ->createQueryBuilder()
            ->select('i')
            ->from(ProductImage::class, 'i')
            ->innerJoin('i.product', 'p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $productIds)
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];

        foreach ($images as $image) {
            $productId = (int) $image->getProduct()->getId();
            $grouped[$productId][] = $image;
        }

        return $grouped;
    }

    private function baseQuery(): QueryBuilder
    {
        return $this->em
            ->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p');
    }

    private function applyFilters(
        QueryBuilder $qb,
        ?string $name,
        ?float $priceMin,
        ?float $priceMax,
    ): void {
        if ($name !== null && $name !== '') {
            // DQL не знает ILIKE: эмуляция регистронезависимого поиска по подстроке
            // (в PostgreSQL LOWER(p.name) LIKE LOWER(:name) работает как ILIKE).
            $qb->andWhere('LOWER(p.name) LIKE :name')
                ->setParameter('name', '%' . mb_strtolower($name) . '%');
        }

        if ($priceMin !== null) {
            $qb->andWhere('p.price >= :priceMin')->setParameter('priceMin', $priceMin);
        }

        if ($priceMax !== null) {
            $qb->andWhere('p.price <= :priceMax')->setParameter('priceMax', $priceMax);
        }
    }
}
