<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ProductDTO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Парсинг XLSX-файла импорта в ProductDTO[].
 * Пишет в БД наружу — только возвращает DTO.
 */
final class XlsxParserService
{
    private const ATTRIBUTE_PREFIX = 'Доп. поле:';

    private const IMAGE_COLUMN = 'Ссылки на фото';

    /** Колонки верхнего уровня: свойство DTO => заголовок в файле. */
    private const COLUMNS = [
        'externalCode' => 'Внешний код',
        'uuid' => 'UUID',
        'name' => 'Наименование',
        'description' => 'Описание',
        'price' => 'Цена: Цена продажи',
        'purchasePrice' => 'Закупочная цена',
    ];

    /**
     * @return ProductDTO[]
     */
    public function parse(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException(sprintf('File "%s" not found', $filePath));
        }

        /** @var array<int, array<int, mixed>> $rows */
        $rows = IOFactory::load($filePath)->getActiveSheet()->toArray(null, true, true, false);

        if ($rows === []) {
            return [];
        }

        /** @var array<int, mixed> $headerRow */
        $headerRow = array_shift($rows);
        $headers = $this->normalizeHeaders($headerRow);

        $columns = $this->resolveColumns($headers);
        $attributeColumns = $this->attributeColumns($headers);
        $imageColumn = $this->findColumn($headers, self::ATTRIBUTE_PREFIX . ' ' . self::IMAGE_COLUMN);

        $products = [];

        foreach ($rows as $row) {
            $externalCode = $this->cellString($row, $columns['externalCode'] ?? null);
            $name = $this->cellString($row, $columns['name'] ?? null);

            // Пустая строка — ни кода, ни наименования.
            if ($externalCode === '' && $name === '') {
                continue;
            }

            $product = new ProductDTO();
            $product->externalCode = $externalCode;
            $product->uuid = $this->cellStringOrNull($row, $columns['uuid'] ?? null);
            $product->name = $name;
            $product->description = $this->cellStringOrNull($row, $columns['description'] ?? null);
            $product->price = $this->cellFloat($row, $columns['price'] ?? null) ?? 0.0;
            $product->purchasePrice = $this->cellFloat($row, $columns['purchasePrice'] ?? null);

            foreach ($attributeColumns as $index => $attributeName) {
                $value = $this->cellString($row, $index);

                if ($value !== '') {
                    $product->attributes[$attributeName] = $value;
                }
            }

            $product->imageUrls = $this->parseImageUrls($this->cellString($row, $imageColumn));

            $products[] = $product;
        }

        return $products;
    }

    /**
     * @param array<int, mixed> $row
     *
     * @return array<int, string>
     */
    private function normalizeHeaders(array $row): array
    {
        $headers = [];

        foreach ($row as $index => $value) {
            $headers[$index] = $this->normalize(is_scalar($value) ? (string) $value : '');
        }

        return $headers;
    }

    /**
     * @param array<int, string> $headers
     *
     * @return array<string, int>
     */
    private function resolveColumns(array $headers): array
    {
        $columns = [];

        foreach (self::COLUMNS as $key => $title) {
            $index = $this->findColumn($headers, $title);

            if ($index !== null) {
                $columns[$key] = $index;
            }
        }

        return $columns;
    }

    /**
     * @param array<int, string> $headers
     *
     * @return array<int, string> индекс колонки => имя атрибута
     */
    private function attributeColumns(array $headers): array
    {
        $prefix = $this->normalize(self::ATTRIBUTE_PREFIX);
        $prefixLength = mb_strlen($prefix);
        $imageColumn = mb_strtolower($this->normalize(self::IMAGE_COLUMN));

        $columns = [];

        foreach ($headers as $index => $header) {
            if (!str_starts_with($header, $prefix)) {
                continue;
            }

            $name = trim(mb_substr($header, $prefixLength));

            // Колонка фото уходит в imageUrls, а не в attributes.
            if ($name === '' || mb_strtolower($name) === $imageColumn) {
                continue;
            }

            $columns[$index] = $name;
        }

        return $columns;
    }

    /**
     * @param array<int, string> $headers
     */
    private function findColumn(array $headers, string $title): ?int
    {
        $needle = $this->normalize($title);

        foreach ($headers as $index => $header) {
            if ($header === $needle) {
                return $index;
            }
        }

        $needleLower = mb_strtolower($needle);

        foreach ($headers as $index => $header) {
            if (mb_strtolower($header) === $needleLower) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $row
     */
    private function cellString(array $row, ?int $index): string
    {
        if ($index === null) {
            return '';
        }

        $value = $row[$index] ?? null;

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }

        if (!is_scalar($value)) {
            return '';
        }

        return $this->normalize((string) $value);
    }

    /**
     * @param array<int, mixed> $row
     */
    private function cellStringOrNull(array $row, ?int $index): ?string
    {
        $value = $this->cellString($row, $index);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<int, mixed> $row
     */
    private function cellFloat(array $row, ?int $index): ?float
    {
        if ($index === null) {
            return null;
        }

        $value = $row[$index] ?? null;

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_scalar($value)) {
            return null;
        }

        // "1 320,00" / "1320,00" / "1320.00" → 1320.00
        $normalized = str_replace([' ', ','], ['', '.'], $this->normalize((string) $value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /**
     * @return string[]
     */
    private function parseImageUrls(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $urls = array_map(static fn(string $url): string => trim($url), explode(',', $value));

        return array_values(array_filter($urls, static fn(string $url): bool => $url !== ''));
    }

    private function normalize(string $value): string
    {
        $value = str_replace("\u{00A0}", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
