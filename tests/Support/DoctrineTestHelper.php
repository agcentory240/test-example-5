<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Entity\Product;
use App\Entity\ProductAttribute;
use App\Entity\ProductImage;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * In-memory SQLite EntityManager со схемой из атрибутов сущностей.
 * Тесты не зависят от PostgreSQL: схема создаётся SchemaTool.
 */
final class DoctrineTestHelper
{
    public static function createEntityManager(): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            [dirname(__DIR__, 2) . '/src/Entity'],
            true,
        );

        // Та же стратегия именования, что и в config/container.php.
        $config->setNamingStrategy(new UnderscoreNamingStrategy());

        $em = new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $config,
        );

        (new SchemaTool($em))->createSchema([
            $em->getClassMetadata(Product::class),
            $em->getClassMetadata(ProductAttribute::class),
            $em->getClassMetadata(ProductImage::class),
        ]);

        return $em;
    }
}
