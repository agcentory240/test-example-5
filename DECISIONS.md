# DECISIONS — ключевые решения

## 1. Импорт: upsert по `external_code`
Уникальный индекс по `external_code`: повторный импорт обновляет товар, а не создаёт дубликат. Атрибуты сравниваются по `key`, изображения — по `url`; отсутствующие в файле удаляются через `orphanRemoval`. Дедупликации картинок по содержимому нет — ТЗ не требует.

## 2. Транзакция на каждый товар
[`ProductImportService`](src/Service/ProductImportService.php:1) открывает `wrapInTransaction()` на одну строку: ошибка откатывает только этот товар, `clear()` сбрасывает состояние EntityManager, строка попадает в `errors`, импорт продолжается. Один битый товар не роняет весь файл.

## 3. `discount` вычисляется на лету
В БД хранятся только `price` и `purchase_price`. `Product::getDiscount()` возвращает `(price - purchasePrice) / price * 100` с округлением до 2 знаков; `null` при отсутствии закупочной цены или нулевой цене. Нет рассинхронизации денормализованного поля.

## 4. Асинхронный импорт: RabbitMQ + polling
`POST /api/import` валидирует файл (расширение, MIME по белому списку, размер), сохраняет в `var/uploads/imports/{jobId}.xlsx`, пишет статус `queued`, диспатчит `ImportProductsMessage` и отвечает `202 {status, jobId}`. Статус читается из `var/imports/{jobId}.json`: `queued → processing → completed | failed`. Messenger собран вручную ([`config/messenger.php`](config/messenger.php:1)), воркер — отдельный контейнер. Polling выбран вместо WebSocket/SSE как минимально достаточное решение для одной задачи.

## 5. Картинки: относительный `path`, статика без auth
Файлы лежат в `public/uploads/products/{productId}/{sha1(url)}.{ext}`, в БД — путь относительно `public/`. Отдаются Nginx напрямую без JWT — осознанное ограничение тестового стенда (в продакшене — подписанные URL или контроллер с auth). Зафиксировано тестом [`ProductImageAccessTest`](tests/Integration/Access/ProductImageAccessTest.php:1).

## 6. JWT в `sessionStorage`, guard синхронный
HS256 (`firebase/php-jwt`), payload `sub`/`email`/`iat`/`exp`, TTL 24 часа. [`AuthMiddleware`](src/Middleware/AuthMiddleware.php:1) закрывает `/api`, кроме логина, health, docs и preflight. На фронте [`AuthGuard`](client/src/app/guards/auth.guard.ts:9) проверяет только наличие токена — просроченный отсекает API (`401`). `sessionStorage` вместо `localStorage`: токен не переживает закрытие вкладки; в продакшене — httpOnly cookie.

## 7. Первый пользователь — seed-скрипт, не миграция
[`bin/seed.php`](bin/seed.php:1) идемпотентен, создаёт `admin@admin` / `admin` через `password_hash()`. Миграции отвечают только за схему и данные не создают.

## 8. Тесты: SQLite in-memory, e2e — smoke
Интеграционные тесты строят схему `SchemaTool` на SQLite in-memory — не зависят от контейнера `db` и работают за миллисекунды; PostgreSQL-специфичные миграции тестами не прогоняются. API-тесты поднимают полное Slim-приложение ([`TestApplication`](tests/Support/TestApplication.php:1)) с заглушкой `MessageBusInterface`. E2E — один сценарий Playwright: логин → импорт `products-no-images.xlsx` → товар в списке.

## 9. Фронтенд: Angular 21 standalone, PrimeNG 21, NgRx для списка
`bootstrapApplication`, lazy loading, zoneless (без `zone.js`), PrimeNG с темой Aura. NgRx только для каталога (actions/effects/reducer/selectors), компоненты через `async pipe`. HTTP — только в сервисах, функциональные guard и interceptor.

## 10. Импорт и список связаны через NgRx
[`ImportService`](client/src/app/services/import.service.ts:1) после `completed` диспатчит `ProductsActions.importCompleted` (один раз на `jobId`), эффект с текущими фильтрами перезагружает первую страницу — новые товары в начале. Список об импорте не знает, NgRx — единственный источник правды.

## 11. Принятые ограничения
Нет дедупликации изображений по содержимому; статика `public/uploads/products/*` без авторизации; `/api/docs` открыт на тестовом стенде; E2E — smoke; экспорт в XLSX не реализован. Rate limit `POST /api/import` — файловый, скользящее окно (10/час с IP). Поиск по названию — `LOWER(...) LIKE`, так как DQL не поддерживает `ILIKE`.

## 12. Обход HTML-заглушки ngrok: заголовок в interceptor + CORS
Бесплатный ngrok на часть браузерных GET-запросов возвращает HTML-заглушку (`ERR_NGROK_6024`) вместо JSON — из-за этого ломался polling `GET /api/import/{jobId}/status`. Решение: [`ngrokInterceptor`](client/src/app/interceptors/ngrok.interceptor.ts:1) добавляет `ngrok-skip-browser-warning: true` ко всем запросам на `environment.apiUrl`, [`CorsMiddleware`](src/Middleware/CorsMiddleware.php:21) разрешает заголовок в `Access-Control-Allow-Headers`. Кастомный заголовок вызывает preflight `OPTIONS`; middleware обрабатывает его до роутинга и отвечает `204`. Заголовок безвреден без ngrok, поэтому применяется всегда, а не только в «публичной» сборке.
