import { test, expect } from '@playwright/test';
import { join } from 'node:path';

const FIXTURE = join(__dirname, 'fixtures', 'products-no-images.xlsx');

/**
 * Smoke e2e: критический сценарий целиком.
 * login → /products → импорт XLSX → товар появился в списке.
 *
 * Требования к окружению: backend + db + rabbitmq + worker подняты,
 * пользователь admin@admin/admin создан (make seed).
 */
test('smoke: login → products → import → product appears', async ({ page }) => {
  // 1. Логин
  await page.goto('/login');
  await page.locator('input[type="email"]').fill('admin@admin');
  await page.locator('input[type="password"]').fill('admin');
  await page.getByRole('button', { name: 'Войти' }).click();

  // 2. Редирект на /products, таблица отрисована
  await expect(page).toHaveURL(/\/products/);
  await expect(page.getByRole('table')).toBeVisible();

  // 3. Открыть drawer импорта и загрузить фикстуру без внешних изображений
  await page.getByRole('button', { name: 'Импорт', exact: true }).click();

  const fileInput = page.locator('input[type="file"]');
  await fileInput.setInputFiles(FIXTURE);

  const uploadButton = page.getByRole('button', { name: 'Загрузить' });
  await expect(uploadButton).toBeEnabled();
  await uploadButton.click();

  // 4. Импорт асинхронный (polling каждые 2 сек) — ждём завершения задачи.
  // Статус проверяем по истории импортов (persistent), а не по тосту:
  // тост «успешно завершён» показывается только при 0 ошибок, а в БД могут
  // остаться «висячие» изображения прошлых импортов (внешние URL недоступны).
  await expect(page.locator('.history-item__status').first()).toHaveText('Завершён', {
    timeout: 60_000,
  });

  // 5. Импорт обработал товар из фикстуры (создал либо обновил).
  await expect(page.locator('.history-item__meta').first()).toContainText(
    /(создано 1|обновлено 1)/,
  );

  // 6. Товар из фикстуры виден в списке (через фильтр, чтобы не зависеть от пагинации).
  await page.getByPlaceholder('Поиск по названию').fill('Товар без фото');
  await expect(page.getByRole('cell', { name: 'Товар без фото' })).toBeVisible();
});
