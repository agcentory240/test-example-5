<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Временный каталог для тестов сервисов, которые пишут на диск.
 */
final class TempDir
{
    public static function create(string $prefix = 'app_test_'): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));

        if (!mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create temp dir "%s"', $dir));
        }

        return $dir;
    }

    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());

                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
