<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\DTO\ProductDTO;
use App\Service\XlsxParserService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class XlsxParserServiceTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures';

    private XlsxParserService $parser;

    protected function setUp(): void
    {
        $this->parser = new XlsxParserService();
    }

    public function testParseExtractsProductsAttributesAndImages(): void
    {
        $products = $this->parser->parse(self::FIXTURES . '/products.xlsx');

        self::assertCount(3, $products);

        $first = $products[0];
        self::assertSame(2, $first->sourceRow);
        self::assertSame('A-001', $first->externalCode);
        self::assertSame('11111111-2222-4333-8444-555555555555', $first->uuid);
        self::assertSame('Бермуды синие', $first->name);
        self::assertSame('Легкие бермуды', $first->description);
        self::assertSame(1320.0, $first->price);
        self::assertSame(990.5, $first->purchasePrice);
        self::assertSame(['Бренд' => 'Zara', 'Размер' => '46(M)'], $first->attributes);
        self::assertSame([
            'https://example.com/a1.jpg',
            'https://example.com/a2.jpg',
        ], $first->imageUrls);
    }

    public function testParseNormalizesPriceStringsAndSkipsEmptyRows(): void
    {
        $products = $this->parser->parse(self::FIXTURES . '/products.xlsx');

        // Вторая строка: цена строкой "1 500,00", пустая строка пропущена.
        self::assertSame('A-002', $products[1]->externalCode);
        self::assertSame(1500.0, $products[1]->price);
        self::assertNull($products[1]->purchasePrice);
        self::assertNull($products[1]->description);
        self::assertSame([], $products[1]->imageUrls);

        // Третья валидная строка — после пустой.
        self::assertSame('A-003', $products[2]->externalCode);
        self::assertSame(5, $products[2]->sourceRow);
        self::assertSame([], $products[2]->attributes);
        self::assertSame([], $products[2]->imageUrls);
    }

    public function testParseFailsForMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->parser->parse(self::FIXTURES . '/missing.xlsx');
    }

    public function testProductDtoSerializesToArray(): void
    {
        $products = $this->parser->parse(self::FIXTURES . '/products-no-images.xlsx');

        self::assertCount(1, $products);
        self::assertInstanceOf(ProductDTO::class, $products[0]);

        $data = $products[0]->toArray();

        self::assertSame('A-010', $data['externalCode']);
        self::assertSame('Товар без фото', $data['name']);
        self::assertSame(100.0, $data['price']);
        self::assertSame([], $data['imageUrls']);
    }
}
