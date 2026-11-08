<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Product;
use App\Entity\ProductImage;

/**
 * Товар для отдачи в API: discount вычисляется на лету,
 * attributes — key-value, images — url + локальный path.
 */
final class ProductViewDTO
{
    /**
     * @param array<string, string>                        $attributes
     * @param list<array{url: string, path: string|null}>  $images
     */
    private function __construct(
        private readonly int $id,
        private readonly string $externalCode,
        private readonly ?string $uuid,
        private readonly string $name,
        private readonly ?string $description,
        private readonly float $price,
        private readonly ?float $purchasePrice,
        private readonly ?float $discount,
        private readonly string $createdAt,
        private readonly array $attributes,
        private readonly array $images,
        private readonly bool $includeAttributes,
    ) {}

    /**
     * Краткое представление для списка: без атрибутов.
     *
     * @param list<ProductImage> $images
     */
    public static function summary(Product $product, array $images): self
    {
        return self::fromEntity($product, $images, false);
    }

    /**
     * Полное представление для карточки: атрибуты + изображения.
     */
    public static function detail(Product $product): self
    {
        /** @var list<ProductImage> $images */
        $images = $product->getImages()->toArray();

        return self::fromEntity($product, $images, true);
    }

    /**
     * @param list<ProductImage> $images
     */
    private static function fromEntity(Product $product, array $images, bool $includeAttributes): self
    {
        $attributes = [];

        if ($includeAttributes) {
            foreach ($product->getAttributes() as $attribute) {
                $attributes[$attribute->getKey()] = $attribute->getValue();
            }
        }

        $imageItems = [];

        foreach ($images as $image) {
            $imageItems[] = ['url' => $image->getUrl(), 'path' => $image->getPath()];
        }

        $purchasePrice = $product->getPurchasePrice();

        return new self(
            id: (int) $product->getId(),
            externalCode: $product->getExternalCode(),
            uuid: $product->getUuid(),
            name: $product->getName(),
            description: $product->getDescription(),
            price: (float) $product->getPrice(),
            purchasePrice: $purchasePrice === null ? null : (float) $purchasePrice,
            discount: $product->getDiscount(),
            createdAt: $product->getCreatedAt()->format(\DATE_ATOM),
            attributes: $attributes,
            images: $imageItems,
            includeAttributes: $includeAttributes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'externalCode' => $this->externalCode,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'purchasePrice' => $this->purchasePrice,
            'discount' => $this->discount,
            'createdAt' => $this->createdAt,
            'images' => $this->images,
        ];

        if ($this->includeAttributes) {
            $data['attributes'] = $this->attributes;
        }

        return $data;
    }
}
