<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Товар, извлечённый из XLSX-файла импорта.
 * Промежуточное представление: парсинг → DTO → (позже) запись в БД.
 */
final class ProductDTO
{
    /**
     * Номер строки в исходном XLSX (шапка — строка 1). Для отчёта об ошибках.
     */
    public int $sourceRow = 0;

    public string $externalCode = '';

    public ?string $uuid = null;

    public string $name = '';

    public ?string $description = null;

    public float $price = 0.0;

    public ?float $purchasePrice = null;

    /** @var array<string, string> */
    public array $attributes = [];

    /** @var string[] */
    public array $imageUrls = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'externalCode' => $this->externalCode,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'purchasePrice' => $this->purchasePrice,
            'attributes' => $this->attributes,
            'imageUrls' => $this->imageUrls,
        ];
    }
}
