<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Entity\Product;
use App\Entity\ProductAttribute;
use App\Entity\ProductImage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Создание товара с атрибутами и изображениями для интеграционных тестов.
 */
final class ProductFactory
{
    /**
     * @param array<string, string> $attributes
     * @param string[]              $imageUrls
     */
    public static function create(
        EntityManagerInterface $em,
        string $externalCode,
        string $name,
        float $price,
        ?float $purchasePrice = null,
        array $attributes = [],
        array $imageUrls = [],
    ): Product {
        $product = (new Product())
            ->setExternalCode($externalCode)
            ->setName($name)
            ->setPrice(self::decimal($price))
            ->setPurchasePrice($purchasePrice === null ? null : self::decimal($purchasePrice));

        foreach ($attributes as $key => $value) {
            $product->addAttribute((new ProductAttribute())->setKey($key)->setValue($value));
        }

        foreach ($imageUrls as $url) {
            $product->addImage((new ProductImage())->setUrl($url));
        }

        $em->persist($product);
        $em->flush();

        return $product;
    }

    private static function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
