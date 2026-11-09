<?php

declare(strict_types=1);

namespace Tests\Integration\Service;

use App\Entity\Product;
use App\Entity\ProductAttribute;
use App\Entity\ProductImage;
use App\Service\ProductCleanupService;
use App\Settings\Settings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\DoctrineTestHelper;
use Tests\Support\ProductFactory;
use Tests\Support\TempDir;

final class ProductCleanupServiceTest extends TestCase
{
    public function testClearAllRemovesRowsAndUploadedFiles(): void
    {
        $em = DoctrineTestHelper::createEntityManager();

        ProductFactory::create(
            $em,
            'A-1',
            'Bermuda blue',
            100.0,
            80.0,
            ['Бренд' => 'Zara'],
            ['https://example.com/1.jpg'],
        );
        ProductFactory::create($em, 'A-2', 'T-shirt', 50.0);

        $publicDir = TempDir::create('cleanup_');
        $productDir = $publicDir . '/uploads/products/1';
        mkdir($productDir, 0o775, true);
        file_put_contents($productDir . '/image.jpg', 'content');

        $service = new ProductCleanupService(
            $em,
            new Settings(['import_public_dir' => $publicDir]),
            new NullLogger(),
        );

        try {
            $service->clearAll();

            self::assertSame(0, (int) $em->createQuery(
                sprintf('SELECT COUNT(p.id) FROM %s p', Product::class),
            )->getSingleScalarResult());
            self::assertSame(0, (int) $em->createQuery(
                sprintf('SELECT COUNT(a.id) FROM %s a', ProductAttribute::class),
            )->getSingleScalarResult());
            self::assertSame(0, (int) $em->createQuery(
                sprintf('SELECT COUNT(i.id) FROM %s i', ProductImage::class),
            )->getSingleScalarResult());

            self::assertDirectoryDoesNotExist($publicDir . '/uploads/products');
            self::assertDirectoryExists($publicDir);
        } finally {
            TempDir::remove($publicDir);
        }
    }
}
