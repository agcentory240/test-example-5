<?php

/**
 * Генератор XLSX-фикстур для тестов: php tests/fixtures/generate_fixtures.php
 * Перезаписывает products*.xlsx рядом с собой. Запускать только при изменении набора фикстур.
 */

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const HEADERS = [
    'Внешний код',
    'UUID',
    'Наименование',
    'Описание',
    'Цена: Цена продажи',
    'Закупочная цена',
    'Доп. поле: Бренд',
    'Доп. поле: Размер',
    'Доп. поле: Ссылки на фото',
];

/**
 * @param array<int, array<int, mixed>> $rows
 */
function writeFixture(string $path, array $rows): void
{
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getActiveSheet()->fromArray(array_merge([HEADERS], $rows), null, 'A1');

    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    echo 'written: ' . $path . PHP_EOL;
}

$dir = __DIR__;

// Основной файл: 3 валидные строки + полностью пустая строка (пропускается парсером).
writeFixture($dir . '/products.xlsx', [
    [
        'A-001',
        '11111111-2222-4333-8444-555555555555',
        'Бермуды синие',
        'Легкие бермуды',
        1320.0,
        990.5,
        'Zara',
        '46(M)',
        'https://example.com/a1.jpg, https://example.com/a2.jpg',
    ],
    [
        'A-002',
        null,
        'Бермуды белые',
        null,
        '1 500,00',
        null,
        'H&M',
        '48(L)',
        null,
    ],
    [null, null, null, null, null, null, null, null, null],
    [
        'A-003',
        null,
        'Футболка',
        null,
        500.0,
        400.0,
        null,
        null,
        null,
    ],
]);

// Без изображений: воркер не должен ходить в сеть.
writeFixture($dir . '/products-no-images.xlsx', [
    ['A-010', null, 'Товар без фото', null, 100.0, null, null, null, null],
]);

// Одна валидная строка и одна невалидная (нет наименования) — для отчёта об ошибках.
writeFixture($dir . '/products-errors.xlsx', [
    ['A-100', null, 'Valid product', null, 100.0, null, null, null, null],
    ['A-101', null, null, null, 50.0, null, null, null, null],
]);
