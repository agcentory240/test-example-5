<?php

declare(strict_types=1);

namespace Tests\Unit\Entity;

use App\Entity\Product;
use App\Entity\ProductAttribute;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    public function testDiscountIsNullWithoutPurchasePrice(): void
    {
        $product = (new Product())->setPrice('1320.00');

        self::assertNull($product->getDiscount());
    }

    public function testDiscountIsNullWhenPriceIsZero(): void
    {
        $product = (new Product())
            ->setPrice('0.00')
            ->setPurchasePrice('100.00');

        self::assertNull($product->getDiscount());
    }

    public function testDiscountIsCalculatedAndRounded(): void
    {
        $product = (new Product())
            ->setPrice('150.00')
            ->setPurchasePrice('100.00');

        self::assertSame(33.33, $product->getDiscount());
    }

    public function testDiscountIsZeroWhenPricesAreEqual(): void
    {
        $product = (new Product())
            ->setPrice('100.00')
            ->setPurchasePrice('100.00');

        self::assertSame(0.0, $product->getDiscount());
    }

    public function testDiscountIsNegativeWhenPurchasePriceIsHigher(): void
    {
        $product = (new Product())
            ->setPrice('100.00')
            ->setPurchasePrice('150.00');

        self::assertSame(-50.0, $product->getDiscount());
    }

    public function testAddAttributeSetsBackReferenceAndAvoidsDuplicates(): void
    {
        $product = (new Product())->setExternalCode('A-1')->setName('Test')->setPrice('10.00');
        $attribute = (new ProductAttribute())->setKey('Бренд')->setValue('Zara');

        $product->addAttribute($attribute);
        $product->addAttribute($attribute);

        self::assertCount(1, $product->getAttributes());
        self::assertSame($product, $attribute->getProduct());
    }

    public function testRemoveAttributeDetachesFromCollection(): void
    {
        $product = (new Product())->setExternalCode('A-1')->setName('Test')->setPrice('10.00');
        $attribute = (new ProductAttribute())->setKey('Бренд')->setValue('Zara');
        $product->addAttribute($attribute);

        $product->removeAttribute($attribute);

        self::assertCount(0, $product->getAttributes());
    }
}
