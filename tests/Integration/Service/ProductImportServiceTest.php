<?php

declare(strict_types=1);

namespace Tests\Integration\Service;

use App\DTO\ProductDTO;
use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Service\ProductImportService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\DoctrineTestHelper;

final class ProductImportServiceTest extends TestCase
{
    private EntityManagerInterface $em;

    private ProductRepository $products;

    private ProductImportService $service;

    protected function setUp(): void
    {
        $this->em = DoctrineTestHelper::createEntityManager();
        $this->products = new ProductRepository($this->em);
        $this->service = new ProductImportService($this->em, $this->products, new NullLogger());
    }

    /**
     * @param array<string, string> $attributes
     * @param string[]              $imageUrls
     */
    private function dto(
        string $externalCode,
        string $name,
        float $price,
        ?float $purchasePrice = null,
        array $attributes = [],
        array $imageUrls = [],
        int $sourceRow = 2,
    ): ProductDTO {
        $dto = new ProductDTO();
        $dto->sourceRow = $sourceRow;
        $dto->externalCode = $externalCode;
        $dto->name = $name;
        $dto->price = $price;
        $dto->purchasePrice = $purchasePrice;
        $dto->attributes = $attributes;
        $dto->imageUrls = $imageUrls;

        return $dto;
    }

    public function testImportCreatesProductWithAttributesAndImages(): void
    {
        $report = $this->service->import([
            $this->dto('A-001', 'Бермуды синие', 1320.5, 990.0, ['Бренд' => 'Zara'], ['https://example.com/1.jpg']),
        ]);

        self::assertSame(1, $report['imported']);
        self::assertSame(0, $report['updated']);
        self::assertSame([], $report['errors']);

        $this->em->clear();
        $product = $this->products->findByExternalCode('A-001');

        self::assertNotNull($product);
        self::assertSame('Бермуды синие', $product->getName());
        self::assertSame(1320.5, (float) $product->getPrice());
        self::assertSame(990.0, (float) $product->getPurchasePrice());
        self::assertCount(1, $product->getAttributes());
        self::assertSame('Zara', $product->getAttributes()->first()->getValue());
        self::assertCount(1, $product->getImages());
        self::assertSame('https://example.com/1.jpg', $product->getImages()->first()->getUrl());
    }

    public function testImportUpdatesExistingProductAndSyncsRelations(): void
    {
        $this->service->import([
            $this->dto(
                'A-001',
                'Старое имя',
                100.0,
                null,
                ['Бренд' => 'Zara', 'Размер' => '46(M)'],
                ['https://example.com/1.jpg', 'https://example.com/2.jpg'],
            ),
        ]);

        $report = $this->service->import([
            $this->dto(
                'A-001',
                'Новое имя',
                120.0,
                null,
                ['Бренд' => 'H&M', 'Цвет' => 'Синий'],
                ['https://example.com/2.jpg', 'https://example.com/3.jpg'],
            ),
        ]);

        self::assertSame(0, $report['imported']);
        self::assertSame(1, $report['updated']);
        self::assertSame([], $report['errors']);

        $this->em->clear();
        $product = $this->products->findByExternalCode('A-001');

        self::assertNotNull($product);
        self::assertSame('Новое имя', $product->getName());
        self::assertSame(120.0, (float) $product->getPrice());

        $attributes = [];
        foreach ($product->getAttributes() as $attribute) {
            $attributes[$attribute->getKey()] = $attribute->getValue();
        }

        self::assertSame(['Бренд' => 'H&M', 'Цвет' => 'Синий'], $attributes);

        $images = [];
        foreach ($product->getImages() as $image) {
            $images[] = $image->getUrl();
        }

        self::assertSame(['https://example.com/2.jpg', 'https://example.com/3.jpg'], $images);
    }

    public function testImportReportsInvalidRowsWithoutStoppingOthers(): void
    {
        $report = $this->service->import([
            $this->dto('BAD-1', '', 100.0, null, [], [], 2),
            $this->dto('A-002', 'Валидный товар', 200.0, null, [], [], 3),
        ]);

        self::assertSame(1, $report['imported']);
        self::assertSame(0, $report['updated']);
        self::assertCount(1, $report['errors']);
        self::assertSame(2, $report['errors'][0]['row']);
        self::assertSame('BAD-1', $report['errors'][0]['externalCode']);
        self::assertSame('name is empty', $report['errors'][0]['message']);

        $this->em->clear();

        self::assertNull($this->products->findByExternalCode('BAD-1'));
        self::assertNotNull($this->products->findByExternalCode('A-002'));
    }

    public function testImportRejectsEmptyExternalCode(): void
    {
        $report = $this->service->import([$this->dto('', 'Товар', 10.0)]);

        self::assertSame(0, $report['imported']);
        self::assertCount(1, $report['errors']);
        self::assertSame('external code is empty', $report['errors'][0]['message']);
    }

    public function testImportRejectsNegativePrice(): void
    {
        $report = $this->service->import([$this->dto('A-003', 'Товар', -1.0)]);

        self::assertSame(0, $report['imported']);
        self::assertCount(1, $report['errors']);
        self::assertSame('price is negative', $report['errors'][0]['message']);
    }

    public function testImportPersistsPriceWithTwoDecimals(): void
    {
        $this->service->import([$this->dto('A-004', 'Округление', 99.999)]);

        $product = $this->products->findByExternalCode('A-004');

        self::assertNotNull($product);
        self::assertSame(100.0, (float) $product->getPrice());
        self::assertInstanceOf(Product::class, $product);
    }
}
