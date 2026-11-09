<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Tests\Support\DoctrineTestHelper;
use Tests\Support\ProductFactory;

final class ProductRepositoryTest extends TestCase
{
    private EntityManagerInterface $em;

    private ProductRepository $repository;

    protected function setUp(): void
    {
        $this->em = DoctrineTestHelper::createEntityManager();
        $this->repository = new ProductRepository($this->em);

        ProductFactory::create($this->em, 'A-1', 'Bermuda blue', 100.0, 80.0);
        ProductFactory::create($this->em, 'A-2', 'Bermuda white', 200.0);
        ProductFactory::create($this->em, 'A-3', 'T-shirt', 50.0);
        ProductFactory::create($this->em, 'A-4', 'Шорты синие', 300.0);
        ProductFactory::create($this->em, 'A-5', 'Sneakers', 1000.0);
    }

    public function testPaginateReturnsItemsAndTotal(): void
    {
        $result = $this->repository->paginate(null, null, null, 1, 2);

        self::assertSame(5, $result['total']);
        self::assertCount(2, $result['items']);
        self::assertSame('A-1', $result['items'][0]->getExternalCode());
        self::assertSame('A-2', $result['items'][1]->getExternalCode());
    }

    public function testPaginateReturnsRequestedPage(): void
    {
        $result = $this->repository->paginate(null, null, null, 2, 2);

        self::assertSame(5, $result['total']);
        self::assertSame(
            ['A-3', 'A-4'],
            array_map(static fn(Product $product): string => $product->getExternalCode(), $result['items']),
        );
    }

    public function testPaginateFiltersByNameCaseInsensitive(): void
    {
        $result = $this->repository->paginate('BERMUDA', null, null, 1, 10);

        self::assertSame(2, $result['total']);
        self::assertSame(
            ['Bermuda blue', 'Bermuda white'],
            array_map(static fn(Product $product): string => $product->getName(), $result['items']),
        );
    }

    public function testPaginateFiltersByPriceRange(): void
    {
        $result = $this->repository->paginate(null, 150.0, 400.0, 1, 10);

        self::assertSame(2, $result['total']);
        self::assertSame(
            ['A-2', 'A-4'],
            array_map(static fn(Product $product): string => $product->getExternalCode(), $result['items']),
        );
    }

    public function testPaginateSortsByNameAscending(): void
    {
        $result = $this->repository->paginate(null, null, null, 1, 5, 'name');

        self::assertSame(
            ['Bermuda blue', 'Bermuda white', 'Sneakers', 'T-shirt', 'Шорты синие'],
            array_map(static fn(Product $product): string => $product->getName(), $result['items']),
        );
    }

    public function testPaginateSortsByPriceAscending(): void
    {
        $result = $this->repository->paginate(null, null, null, 1, 5, 'price');

        self::assertSame(
            ['A-3', 'A-1', 'A-2', 'A-4', 'A-5'],
            array_map(static fn(Product $product): string => $product->getExternalCode(), $result['items']),
        );
    }

    public function testPaginateSortsByPriceDescending(): void
    {
        $result = $this->repository->paginate(null, null, null, 1, 5, 'price', 'desc');

        self::assertSame(
            ['A-5', 'A-4', 'A-2', 'A-1', 'A-3'],
            array_map(static fn(Product $product): string => $product->getExternalCode(), $result['items']),
        );
    }

    public function testPaginateFallsBackToIdForUnknownSort(): void
    {
        $result = $this->repository->paginate(null, null, null, 1, 2, 'price; DROP TABLE products');

        self::assertSame(['A-1', 'A-2'], array_map(
            static fn(Product $product): string => $product->getExternalCode(),
            $result['items'],
        ));
    }

    public function testPaginateReturnsEmptyResult(): void
    {
        $result = $this->repository->paginate('no such product', null, null, 1, 10);

        self::assertSame(0, $result['total']);
        self::assertSame([], $result['items']);
    }

    public function testFindByExternalCode(): void
    {
        $product = $this->repository->findByExternalCode('A-3');

        self::assertNotNull($product);
        self::assertSame('T-shirt', $product->getName());
        self::assertNull($this->repository->findByExternalCode('missing'));
    }

    public function testFindWithDetailsLoadsAttributesAndImages(): void
    {
        $product = ProductFactory::create(
            $this->em,
            'A-10',
            'Detailed',
            150.0,
            100.0,
            ['Бренд' => 'Zara', 'Размер' => '46(M)'],
            ['https://example.com/1.jpg', 'https://example.com/2.jpg'],
        );

        $this->em->clear();

        $found = $this->repository->findWithDetails((int) $product->getId());

        self::assertNotNull($found);
        self::assertCount(2, $found->getAttributes());
        self::assertCount(2, $found->getImages());
        self::assertSame(33.33, $found->getDiscount());
        self::assertNull($this->repository->findWithDetails(999999));
    }

    public function testFindImagesByProductIdsGroupsByProduct(): void
    {
        $withImages = ProductFactory::create(
            $this->em,
            'A-11',
            'With images',
            10.0,
            null,
            [],
            ['https://example.com/1.jpg', 'https://example.com/2.jpg'],
        );
        $withoutImages = ProductFactory::create($this->em, 'A-12', 'No images', 10.0);

        $grouped = $this->repository->findImagesByProductIds([
            (int) $withImages->getId(),
            (int) $withoutImages->getId(),
        ]);

        self::assertArrayHasKey((int) $withImages->getId(), $grouped);
        self::assertCount(2, $grouped[(int) $withImages->getId()]);
        self::assertArrayNotHasKey((int) $withoutImages->getId(), $grouped);
        self::assertSame([], $this->repository->findImagesByProductIds([]));
    }
}
