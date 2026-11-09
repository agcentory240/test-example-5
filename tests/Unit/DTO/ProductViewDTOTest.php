<?php

declare(strict_types=1);

namespace Tests\Unit\DTO;

use App\DTO\ProductViewDTO;
use App\Entity\Product;
use App\Entity\ProductAttribute;
use App\Entity\ProductImage;
use PHPUnit\Framework\TestCase;

final class ProductViewDTOTest extends TestCase
{
    private function product(): Product
    {
        $product = (new Product())
            ->setExternalCode('A-001')
            ->setUuid('11111111-2222-4333-8444-555555555555')
            ->setName('Бермуды синие')
            ->setDescription('Описание')
            ->setPrice('150.00')
            ->setPurchasePrice('100.00');

        $product->addAttribute((new ProductAttribute())->setKey('Бренд')->setValue('Zara'));
        $product->addAttribute((new ProductAttribute())->setKey('Размер')->setValue('46(M)'));

        $product->addImage((new ProductImage())->setUrl('https://example.com/a1.jpg'));
        $product->addImage((new ProductImage())->setUrl('https://example.com/a2.jpg')->setPath('uploads/products/1/a2.jpg'));

        return $product;
    }

    public function testDetailContainsAttributesAndImages(): void
    {
        $data = ProductViewDTO::detail($this->product())->toArray();

        self::assertSame('A-001', $data['externalCode']);
        self::assertSame('Бермуды синие', $data['name']);
        self::assertSame(150.0, $data['price']);
        self::assertSame(100.0, $data['purchasePrice']);
        self::assertSame(33.33, $data['discount']);
        self::assertSame(['Бренд' => 'Zara', 'Размер' => '46(M)'], $data['attributes']);
        self::assertSame([
            ['url' => 'https://example.com/a1.jpg', 'path' => null],
            ['url' => 'https://example.com/a2.jpg', 'path' => 'uploads/products/1/a2.jpg'],
        ], $data['images']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['createdAt']);
    }

    public function testSummaryOmitsAttributesButKeepsImages(): void
    {
        $product = $this->product();
        $images = $product->getImages()->toArray();

        $data = ProductViewDTO::summary($product, $images)->toArray();

        self::assertArrayNotHasKey('attributes', $data);
        self::assertCount(2, $data['images']);
        self::assertSame(33.33, $data['discount']);
    }

    public function testDiscountIsNullWithoutPurchasePrice(): void
    {
        $product = (new Product())
            ->setExternalCode('A-002')
            ->setName('Без закупочной цены')
            ->setPrice('500.00');

        $data = ProductViewDTO::summary($product, [])->toArray();

        self::assertNull($data['purchasePrice']);
        self::assertNull($data['discount']);
        self::assertSame([], $data['images']);
    }
}
