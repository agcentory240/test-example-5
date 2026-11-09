# DECISIONS — ключевые решения

## Импорт: идемпотентность и атомарность
Upsert по `external_code` (уникальный индекс в миграции) — повторный импорт обновляет товары, а не создаёт дубликаты. Транзакция открывается **на каждый товар** (`EntityManager::wrapInTransaction()`): ошибка откатывает только текущий товар, затем `clear()` сбрасывает «грязное» состояние, строка попадает в `errors`, импорт продолжается.

Атрибуты сравниваются по `key`, изображения — по `url`: найденная запись обновляется/не дублируется, отсутствующая в файле удаляется через `orphanRemoval` (у изображения при этом удаляется и файл). Скачивание картинок с дедупликацией по содержимому **не реализовано** — ТЗ этого не требует; дедупликация только по `url` в рамках товара.

## `discount` не хранится в БД
Вычисляется на лету методом `Product::getDiscount()`: `(price - purchasePrice) / price * 100`, округление до 2 знаков; при `purchasePrice = null` или `price = 0` — `null`. В БД лежат только `price` и `purchase_price` (`decimal`, в PHP — строки).

## Асинхронный импорт через RabbitMQ
`POST /api/import` валидирует файл (расширение `.xlsx`, MIME по белому списку, лимит 10 МБ), сохраняет в `var/uploads/imports/{jobId}.xlsx`, пишет статус `queued`, диспатчит `ImportProductsMessage(jobId, filePath)` и отвечает `202 {status, jobId}`. `GET /api/import/{jobId}/status` отдаёт `queued → processing → completed | failed` с полями `imported`, `updated`, `errors`, `error`. MessageBus собран вручную в [`config/messenger.php`](config/messenger.php:1) (без FrameworkBundle), воркер — `Symfony\Component\Messenger\Worker` в отдельном контейнере.

## Картинки: относительный `path`, статика без авторизации
Скачанные файлы лежат в `public/uploads/products/{productId}/{sha1(url)}.{ext}`, в БД пишется **относительный от `public/`** путь. Отдаются статикой Nginx напрямую, **без проверки JWT** — осознанное ограничение тестового стенда (в проде — подписанные URL или PHP-контроллер с auth). Зафиксировано тестом [`ProductImageAccessTest`](tests/Integration/Access/ProductImageAccessTest.php:78).

## Авторизация: JWT в `sessionStorage`, guard синхронный
JWT HS256 (`firebase/php-jwt` v7), TTL 24 часа, payload — `sub`/`email`/`iat`/`exp`. `AuthMiddleware` закрывает всю группу `/api`, кроме `/api/login`, `/api/health*`, `/api/docs*` и preflight `OPTIONS`. На фронте токен в `sessionStorage` (в проде — httpOnly cookie), `AuthGuard` проверяет только наличие токена, просроченный отсекает бэкенд. `/api/docs` и `/api/docs.json` **открыты** для проверяющего — в проде закрыть (auth или ограничение по IP).

## Первый пользователь — seed-скрипт
[`bin/seed.php`](bin/seed.php:1), не миграция: миграции данные не создают. Идемпотентен, креды `admin@admin` / `admin`, пароль через `password_hash(PASSWORD_DEFAULT)`.

## Тесты: SQLite in-memory + e2e smoke на Playwright
Схема для интеграционных тестов строится `SchemaTool` по атрибутам сущностей на SQLite in-memory — контейнер не зависит от `db`, схема создаётся за миллисекунды; миграции PostgreSQL-специфичны и тестами не прогоняются. Интеграционные API-тесты поднимают полное Slim-приложение ([`TestApplication`](tests/Support/TestApplication.php:34)) с заглушкой `MessageBusInterface`.

## E2E: один smoke-сценарий
Playwright ([`client/playwright.config.ts`](client/playwright.config.ts:1)) + Chromium. Сценарий [`client/e2e/smoke.spec.ts`](client/e2e/smoke.spec.ts:1): логин `admin@admin`/`admin` → `/products` → импорт XLSX → товар появляется в списке. Dev-сервер Angular поднимается через `webServer` (`ng serve`, порт 4200), бэкенд/БД/RabbitMQ/воркер — отдельно (`make up && make migrate && make seed`). Используется фикстура `products-no-images.xlsx` (без внешних URL). Завершение импорта проверяется по статусу в истории импортов, а не по тосту «успешно завершён»: `ImportProductsHandler::downloadImages()` ретраит **все** изображения без `path`, поэтому «висячие» недоступные URL прошлых импортов дают `errors > 0` и warning-тост. Запуск: `cd client && npm run e2e` (или `make e2e`). Учтён rate limit `POST /api/import` (10/час) — при частых прогонах возможен 429.

## Фронтенд: Angular standalone, PrimeNG, NgRx
Angular 21 standalone (`bootstrapApplication`, `app.routes.ts`, lazy loading), Zoneless без `zone.js`, PrimeNG 21 с темой Aura через `providePrimeNG`. NgRx — только для списка товаров (actions/effects/reducer/selectors), компоненты через `async pipe`. Модели — интерфейсы, HTTP — только в сервисах, функциональные interceptor и guard.

## Импорт и список связаны через NgRx
[`ImportService`](client/src/app/services/import.service.ts:1) после завершения задачи диспатчит `ProductsActions.importCompleted({jobId, errors})` (один раз на jobId). Эффект с `withLatestFrom(selectProductsQuery)` перезагружает список на первой странице с текущими фильтрами и сортировкой — новые товары появляются в начале. Список про импорт не знает, NgRx — единственный источник правды.

## Прочие решения
Парсинг XLSX — колонки по заголовку, не по индексу (`Доп. поле: <имя>` → атрибуты, `Ссылки на фото` → изображения; пустые строки и NBSP пропускаются). API каталога — серверная пагинация, фильтр по имени через `LOWER(...) LIKE` (DQL не поддерживает `ILIKE`), отдельный `COUNT` для `meta.total`. Rate limit `POST /api/import` — файловый, скользящее окно. Валидация файла и DTO — до записи в БД. Локальная инфраструктура — Docker Compose с портами/образами по ТЗ, детали в README.
