# DECISIONS — принятые решения и допущения

## Инфраструктура (блок 1)

1. **Nginx root — `/var/www/html/public`, а не `/var/www/public`.**
   Nginx и PHP-FPM должны видеть файлы по одному пути (`SCRIPT_FILENAME`). `app` собирается с `WORKDIR /var/www/html`, поэтому nginx монтирует проект в `/var/www/html` и использует `root /var/www/html/public`.

2. **Nginx монтирует весь проект (`./:/var/www/html:ro`), не только `public/`.**
   Иначе PHP-FPM не находит `index.php` по переданному `SCRIPT_FILENAME`.

3. **`.env` не обязателен.** В `docker-compose.yml` используются `${VAR:-default}`, поэтому `make up` работает без `.env`. `.env.example` — референс.

4. **Порты по умолчанию по ТЗ:** nginx `8080:80`, PostgreSQL `5432:5432`, RabbitMQ `5672`/`15672`. Если `5432` занят локальным Postgres — меняется только маппинг порта.

5. **`docker compose` / `docker-compose`.** В `Makefile` авто-детект: используется `docker compose`, если плагин доступен, иначе standalone `docker-compose`.

6. **Базовый образ — Debian trixie.** Runtime-пакеты указаны под trixie: `libzip5`, `libicu76` (в bookworm были `libzip4`, `libicu72`).

7. **`ext-xml`, `ext-dom`, `ext-simplexml`, `ext-xmlreader/writer`, `ext-mbstring`, `ext-fileinfo` не собираются вручную** — они входят в базовый `php:8.3-fpm`. Вручную: `pdo_pgsql`, `zip`, `gd`, `intl`, `bcmath`, `amqp` (через pecl).

8. **`composer.json` добавлен в инфраструктурном блоке.** Без него `composer install` в multi-stage builder не выполняется. Версии — только стек из `TASK.md`/`AGENTS.md`.

9. **PhpSpreadsheet `^5.0`.** Composer 2.9 блокирует установку всех версий `4.x` из-за security advisories.

10. **`vendor` — анонимный volume** (`/var/www/html/vendor`). При изменении зависимостей контейнеры нужно пересоздать с `--renew-anon-volumes`:
    `docker-compose up -d --build --force-recreate --renew-anon-volumes app worker`.

11. **`worker` запускает `php bin/worker.php`** (блок 4). До реализации консьюмера контейнер перезапускался `restart: unless-stopped`; сейчас воркер работает постоянно.

12. **Маппинг портов БД/брокера наружу оставлен** (для локального подключения клиентами). В проде их нужно убрать.

## Doctrine-сущности (блок 3)

13. **Поля берутся из реального XLSX:** `externalCode` ← «Внешний код», `uuid` ← «UUID», `name` ← «Наименование», `description` ← «Описание», `price` ← «Цена: Цена продажи», `purchasePrice` ← «Закупочная цена». Атрибуты — колонки «Доп. поле: *» (префикс срезается), изображения — «Доп. поле: Ссылки на фото» (разбивка по запятой).

14. **`discount` не хранится в БД.** Считается на лету методом `Product::getDiscount()`: `(price - purchasePrice) / price * 100`, округление до 2 знаков. При `purchasePrice = null` или `price = 0` возвращает `null`.

15. **Имена таблиц:** `products`, `product_attributes`, `product_images`. Для FK `product_id` добавлены индексы `idx_product_attributes_product_id` / `idx_product_images_product_id` (PostgreSQL не создаёт индексы на FK автоматически).

16. **Денежные поля — `string` в PHP** (`decimal` в Doctrine возвращает строку). Приведение к `float` только внутри `getDiscount()`.

17. **`id` объявлен как `?int $id = null`** — стандартный паттерн Doctrine, чтобы PHPStan (`property.onlyRead` / `property.unusedType`) не ругался на поля, записываемые через рефлексию. Игнор для `property.unusedType` вынесен в `phpstan.neon`.

18. **Связи двусторонние с `cascade: ['persist', 'remove']` + `orphanRemoval: true`.** `Product::addAttribute()` / `addImage()` синхронизируют обратную сторону (`setProduct()`).

19. **`createdAt` (`datetime_immutable`) инициализируется в конструкторе** (`new DateTimeImmutable()`). Doctrine при гидрации существующих записей перезаписывает значение из БД.

## Миграции (блок 3.3)

20. **Naming strategy — `UnderscoreNamingStrategy`** (`config/container.php`). В сущностях свойства `externalCode`, `purchasePrice`, `createdAt`, а в БД — `external_code`, `purchase_price`, `created_at`. Без этого Doctrine создавала camelCase-колонки, а ТЗ требует `external_code`.

21. **Namespace миграций — `Migrations`**, путь — `migrations/` (`config/migrations.php`). Классы миграций грузятся самим Doctrine Migrations (`require_once`), поэтому PSR-4-автозагрузка для них в `composer.json` не нужна.

22. **`orm:validate-schema` показывает `[ERROR]` из-за служебной таблицы `doctrine_migration_versions`** — она есть в БД, но не описана в маппинге, поэтому SchemaTool хочет её удалить. Это ожидаемо: `doctrine/migrations` использует `tablesExist()` на том же соединении, и глобальный `schemaAssetsFilter` сломал бы миграции. Реальная проверка синхронизации — `php bin/doctrine migrations:diff` (должен возвращать `No changes detected`).

## Загрузка файла импорта (блок 6.1)

23. **`POST /api/import` отвечает `202 Accepted`** — импорт асинхронный. На этом шаге файл только валидируется и сохраняется в `var/uploads/imports/{jobId}.xlsx`; диспатч в Messenger и парсинг — следующие шаги.

24. **UUID v4 генерируется вручную** (`random_bytes()` → `ImportFileStorage::generateJobId()`): `ramsey/uuid` в `composer.json` нет, а добавлять зависимости без согласования нельзя.

25. **Валидация файла:** расширение строго `.xlsx`; MIME — по белому списку (`...spreadsheetml.sheet`, `application/vnd.ms-excel`, `application/zip`, `application/octet-stream`), т.к. curl/браузер может отдать `octet-stream`. Лимит 10 МБ (`import_max_file_size` в `config/settings.php`), `php.ini` поднимает `upload_max_filesize` до 64 МБ.

26. **Если превышен `post_max_size`, `$_FILES` приходит пустым** — ответ будет `{"error":"file is required"}` (400), а не `file too large`. Лимит PHP выше лимита приложения, поэтому на практике это не срабатывает.

## Docker: общий каталог данных (доработка блока 1)

27. **`var/` монтируется bind-mount'ом `./var:/var/www/html/var` — одинаково в `app` и `worker`.** Раньше на этом пути висел анонимный volume: он перекрывал `./var` с хоста (загруженные файлы не видны локально) и создавался отдельно для каждого контейнера, поэтому воркер не смог бы прочитать файл, сохранённый `app`. Пробовали именованный volume `var_data` — файлы становились общими для контейнеров, но по-прежнему невидимыми в проекте. Bind-mount решает обе задачи: загрузки лежат в `./var/uploads/imports/` и доступны `app`, `worker` и хосту. Минус — `var/` попадает в Docker Desktop I/O; для dev это приемлемо, в проде каталог нужно вынести в volume.
28. **После пересоздания `app` нужно перезапускать `nginx`** (`docker compose restart nginx`). nginx кэширует IP апстрима при старте, и после `--force-recreate` контейнер `app` получает новый адрес — запросы висят до таймаута и отдают `504 Gateway Time-out`.

## Парсинг XLSX (блок 5.2)

29. **Парсинг вынесен в `XlsxParserService` и возвращает `ProductDTO[]`** — без записи в БД и без диспатча в очередь. Временный роут `POST /api/import/parse-test` отдаёт первые 3 товара для проверки парсинга.
30. **Колонки ищутся по названию заголовка, не по индексу** (trim + сжатие пробелов + замена NBSP). Если в файле переставят колонки — парсинг не сломается. Fallback — регистронезависимое сравнение.
31. **`Доп. поле: <имя>` → `attributes` с ключом без префикса.** Исключение — `Доп. поле: Ссылки на фото`: идёт в `imageUrls` (split по запятой + trim). Пустые значения в `attributes` не попадают.
32. **Пропуск строк:** строка считается пустой и пропускается, если пусты и «Внешний код», и «Наименование». Если цена не парсится числом — `price = 0.0` (DTO не-nullable по контракту), `purchasePrice` остаётся `null`.
33. **`phpoffice/phpspreadsheet` установлен в `app`-контейнере.** `vendor` — анонимный volume: после обновления зависимостей контейнеры нужно пересоздать с `--renew-anon-volumes` (см. п. 10).

## API каталога (блок 6)

34. **`GET /api/products` — серверная пагинация + фильтры.** Параметры: `page` (с 1), `limit` (1..100, по умолчанию 20), `name` (подстрока), `price_min`/`price_max`, `sort` (`id`|`name`, ASC). Ответ: `{data, meta: {page, limit, total, totalPages}}`. `total` считается отдельным `COUNT(p.id)` по тем же фильтрам.
35. **Поиск по названию — регистронезависимый, через `LOWER(p.name) LIKE :name`.** DQL Doctrine не поддерживает `ILIKE` (парсер падает на нём), поэтому ILIKE эмулируется приведением обеих сторон к нижнему регистру (`mb_strtolower` в PHP + `LOWER()` в PostgreSQL). Семантика для PostgreSQL та же, что у `ILIKE '%...%'`.
36. **Карточка `GET /api/products/{id}`** отдаёт `attributes` как key-value и `images` как `[{url, path}]`; `discount` — вычисляемое поле из `Product::getDiscount()` (в БД не хранится).
37. **Формат списка и карточки задаёт `ProductViewDTO`.** В списке атрибуты не отдаются, изображения — отдаются (загружаются одним батч-запросом `findImagesByProductIds`, без N+1). Карточка — атрибуты + изображения одним запросом с `LEFT JOIN`.
38. **Swagger — `zircote/swagger-php` 7.4, описание через атрибуты (`OpenApi\Spec as OA`), а не докблок-аннотации.** В 7.x докблок-аннотации помечены deprecated (удаление в 8.0), атрибуты — рекомендованный путь. Аннотации добавлены на `GET /api/products`, `GET /api/products/{id}`, `POST /api/import`; `Info`/`Server` — в `src/OpenApi/OpenApiDefinition.php`.
39. **`GET /api/docs` — Swagger UI с CDN (unpkg, версия зафиксирована), `GET /api/docs.json` — спека, генерируется на лету.** Кэширование спеки не делается (MVP): сканируются только `src/Controller` и `src/OpenApi`.
40. **Rate limit `POST /api/import` — файловый, `var/rate_limit/{sha1(ip)}.json`.** Скользящее окно: в файле список меток времени, старые вычищаются при каждом запросе; доступ через `flock(LOCK_EX)`. Лимит — `RATE_LIMIT_MAX` (10) за `RATE_LIMIT_WINDOW` (3600 с). При превышении — `429` + `Retry-After` и `X-RateLimit-*`. IP берётся из `REMOTE_ADDR` (nginx→php-fpm), `X-Forwarded-For` не используется — он подделывается клиентом.
41. **`composer.json`: `config.platform.php = 8.3.36`.** Без этого composer (на образе PHP 8.4+) выбирал `symfony/type-info`/`symfony/yaml` v8.1, требующие PHP ≥ 8.4.1, и сборка `app` (PHP 8.3) падала. Фиксация платформы даёт lock-файл, совместимый с контейнером.
42. **`vendor` в контейнере — анонимный volume (п. 10):** после `composer require` на хосте контейнер нужно пересоздать с `--force-recreate --renew-anon-volumes` (или `docker cp vendor/. <container>:/var/www/html/vendor/`), иначе PHP не видит новые пакеты.

## Запись товаров в БД (блок 5.3)

34. **Upsert по `external_code`** через [`ProductRepository::findByExternalCode()`](src/Repository/ProductRepository.php:20). Уникальный индекс `UNIQ_B3BA5A5AE20A50B4` уже есть в миграции — повторный импорт обновляет, а не создаёт дубликаты.
35. **Транзакция — на каждый товар** (`EntityManager::wrapInTransaction()`). Ошибка откатывает только текущий товар, затем `EntityManager::clear()` сбрасывает «грязное» состояние, ошибка попадает в отчёт, импорт продолжается (`ProductImportService::import()`).
36. **Атрибуты сравниваются по `key`:** найден → обновляется значение; нет → создаётся; отсутствует в DTO → удаляется через `orphanRemoval`. Дубликаты по key в старых данных тоже удаляются.
37. **Изображения сравниваются по `url`:** найден → запись не трогается (скачанный `path` сохраняется); нет → создаётся `ProductImage` с `path = null`; отсутствует в DTO → удаляется, а если `path` не null и файл существует — файл удаляется с диска. Ошибка удаления файла не прерывает импорт (только warning в лог).
38. **Валидация DTO перед записью:** `externalCode` и `name` обязательны, `price >= 0`. Строка с ошибкой валидации попадает в `errors`, остальные импортируются.
39. **Номер строки для отчёта** — `ProductDTO::$sourceRow`, выставляется в парсере: шапка — строка 1, данные начинаются со строки 2.
40. **`POST /api/import` теперь синхронный:** сохраняет файл → парсит → пишет в БД → возвращает `{status, imported, updated, errors}` (200). Ошибка парсинга файла целиком — 422. Файл остаётся в `var/uploads/imports/` для будущего асинхронного шага. Messenger — следующий блок.

## Symfony Messenger + RabbitMQ (блок 4.1)

41. **Версии — Symfony 7.4.** PHP в контейнере 8.3, а `symfony/messenger` и `symfony/amqp-messenger` 8.x требуют PHP ≥ 8.4.1, поэтому Composer зафиксировал `^7.4`.
42. **MessageBus собирается вручную в [`config/messenger.php`](config/messenger.php:1)**, без FrameworkBundle. Стек: `SendMessageMiddleware` (маршрутизация в транспорт) → `HandleMessageMiddleware` (локальный обработчик). `SendersLocatorInterface` реализован анонимным классом: в `async` уходит только `TestMessage`.
43. **API `amqp-messenger` 7.4:** `AmqpSender` и `AmqpReceiver` принимают только `Connection` (имя exchange больше не передаётся в конструктор — оно задаётся в `Connection`). Exchange — `messages` типа `fanout`, очередь — `messages`, `auto_setup` включён.
44. **Воркер использует `Symfony\Component\Messenger\Worker`, а не ручной цикл `Connection::get()`.** Worker сам делает `get()` → `dispatch()` → `ack/reject`, добавляет `ReceivedStamp` (благодаря чему `SendMessageMiddleware` не отправляет сообщение обратно в очередь) и логирует результат. Ручной цикл пришлось бы дописывать обработкой ошибок/подтверждений — здесь это уже готово.
45. **Retry пока не подключён.** Для повторных попыток нужен `SendFailedMessageForRetryMiddleware` + `RetryStrategy` в стеке шины; это добавится вместе с `ImportProductsMessage`.
46. **Проверка связки:** временный `GET /api/test-dispatch` кладёт `TestMessage` в очередь, воркер пишет `Handled: Hello RabbitMQ` в stdout и `var/log/messenger.log`, сообщение подтверждается (`ack`) и исчезает из очереди.

## Асинхронный импорт через очередь (блок 4.2)

47. **`POST /api/import` снова асинхронный:** сохраняет файл в `var/uploads/imports/{jobId}.xlsx`, пишет статус `queued` в `var/imports/{jobId}.json`, диспатчит `ImportProductsMessage(jobId, filePath)` и отвечает `202 {status: queued, jobId}`. Синхронный парсинг/импорт из контроллера убран.
48. **`GET /api/import/{jobId}/status`** читает `var/imports/{jobId}.json` и отдаёт `{jobId, status, imported, updated, errors}`; неизвестный jobId — 404, невалидный — 400. Статусы: `queued` → `processing` → `completed` | `failed`.
49. **`ImportProductsHandler` собирается через PHP-DI** (`$c->get(ImportProductsHandler::class)` в `messenger.handlers_locator`): парсит XLSX, пишет товары, скачивает картинки, обновляет статус. Любая неожиданная ошибка помечает задачу `failed` и сообщение подтверждается (без poison-цикла).
50. **Скачивание картинок — `ImageDownloaderService` (Symfony HttpClient).** Для всех `product_images` с `path IS NULL` файл кладётся в `public/uploads/products/{productId}/{sha1(url)}.{ext}`; в БД пишется относительный путь `uploads/products/{productId}/{file}`. Ошибка одной картинки идёт в `errors` (с `type: image`), импорт не прерывается.
51. **Расширение файла определяется по `Content-Type`**, fallback — по расширению URL, финальный fallback — `jpg`. Принимаются только `image/*`.
52. **`path` в БД — относительный от `public/`.** `ProductImportService::deleteImageFile()` резолвит его как `{projectRoot}/public/{path}`, чтобы при удалении устаревших изображений файл удалялся с диска.

## Тесты (блок 7)

53. **Тестовая БД — SQLite in-memory, схема через `Doctrine\ORM\Tools\SchemaTool`** по атрибутам сущностей ([`DoctrineTestHelper`](tests/Support/DoctrineTestHelper.php:22)). PostgreSQL для тестов не требуется: контейнер не зависит от состояния `db`, схема создаётся за миллисекунды. Миграции тестами не прогоняются — они PostgreSQL-специфичны, а схема соответствует сущностям.
54. **`phpunit.xml` — два набора:** `Unit` (`tests/Unit`, без БД) и `Integration` (`tests/Integration`, с SQLite). Запуск: `make test` → `vendor/bin/phpunit`.
55. **XLSX-фикстуры — реальные файлы в `tests/fixtures/`** (`products.xlsx`, `products-no-images.xlsx`, `products-errors.xlsx`), сгенерированы скриптом [`generate_fixtures.php`](tests/fixtures/generate_fixtures.php:1) через PhpSpreadsheet. Перегенерировать: `docker compose exec app php tests/fixtures/generate_fixtures.php`.
56. **Воркер в тестах не ходит в сеть:** для `ImportProductsHandler` используется фикстура без URL изображений; сам `ImageDownloaderService` не подменяется (класс `final`), скачивание покрывается только через фикстуры без картинок. Ошибки картинок в тестах не воспроизводятся.
57. **`ImportController` проверяется на Slim PSR-7 (`Slim\Psr7\UploadedFile`)**, а не через HTTP-сервер: это интеграционный тест контроллера, e2e (Playwright) — отдельный этап после фронтенда.
58. **E2E-тесты (Playwright) не пишутся** — по указанию: после фронтенда. Пункт отмечен в `CHECKLIST.md` как невыполненный осознанно.

## Фронтенд: Angular 21 + PrimeNG 21 (блок 8.1)

59. **Клиент переведён с Angular 15 (NgModule) на Angular 21 (standalone).** Удалены `app.module.ts` и `app-routing.module.ts`, точка входа — `bootstrapApplication` в [`main.ts`](client/src/main.ts:1), провайдеры — [`app.config.ts`](client/src/app/app.config.ts:1), роутинг — [`app.routes.ts`](client/src/app/app.routes.ts:1).
60. **PrimeNG 21 + `@primeuix/themes@2.0.3`.** Версия тем выбрана по зависимости `primeng@21` от `@primeuix/styles@^2.0.3`; пакет `@primeng/themes` (v18) не используется. Тема Aura подключается через `providePrimeNG({theme:{preset: Aura}})`, глобальный CSS-файл темы не импортируется.
61. **Zoneless:** `zone.js` удалён из `package.json` и из `polyfills` в [`angular.json`](client/angular.json:1); `provideZoneChangeDetection` и `provideAnimationsAsync` не подключаются — PrimeNG 21 использует нативные CSS-анимации.
62. **Сборщик — `@angular-devkit/build-angular:application`** (Angular 21), тесты — `:karma` без `zone.js` в `polyfills`. `strict: true` сохранён в [`tsconfig.json`](client/tsconfig.json:1).
63. **CORS уже покрывал фронтенд:** [`CorsMiddleware`](src/Middleware/CorsMiddleware.php:18) читает `CORS_ALLOWED_ORIGINS` (по умолчанию `http://localhost:4200,http://localhost:5173`), правки не потребовались.

## Фронтенд: модели и сервисы (блок 8.2)

64. **`attributes` в API приходит в двух форматах:** в карточке товара — key-value-объект (`ProductViewDTO::detail()`), в списке поле отсутствует вовсе. Поэтому [`ProductService`](client/src/app/services/product.service.ts:1) нормализует его в `ProductAttribute[]` (`Object.entries`), а отсутствующее значение приводит к `[]`. Интерфейс `Product` всегда соответствует реальным данным.
65. **`HttpParams` собираются только из непустых значений:** `undefined`/`null` не передаются, `name` дополнительно тримится и отбрасывается, если пустой — иначе в запрос попадала бы строка `"undefined"`.
66. **`httpErrorInterceptor`** — функциональный (`HttpInterceptorFn`), регистрируется через `provideHttpClient(withInterceptors([...]))`. Логирует `status 0` (сеть), `401` и `500`, затем `throwError` — ошибка не глотается, компоненты могут показать сообщение.
67. **`ImportService.upload()` отправляет `FormData` без ручного `Content-Type`** — браузер сам проставляет `multipart/form-data` с boundary.
68. **`ImportUploadResponse` вынесен в [`import-job-status.model.ts`](client/src/app/models/import-job-status.model.ts:1)** рядом с `ImportJobResult`, чтобы сервис не описывал анонимный тип.

## Фронтенд: импорт с тостами и логами поллинга (блок 8.5.3)

69. **Polling живёт в [`ImportService`](client/src/app/services/import.service.ts:1)**, а не в компоненте: `interval(2000)` + `switchMap` + `takeWhile(terminal)` запускаются из сервиса, поэтому закрытие драуера не убивает опрос. История (`history$`) и логи (`logs$`) — `BehaviorSubject` в сервисе (`providedIn: 'root'`), сохраняются между открытиями драуера.
70. **`startImport()` первым эмитит `queued`** (`concat(of(queued), polling$)`), чтобы компонент показал тост «поставлен в очередь» ровно один раз — на успешный upload. Ошибка upload приходит в `error`, ошибка поллинга дополнительно помечает запись в истории как `failed`.
71. **Статус из API не содержит стадии «скачивание изображений».** Лог «Скачивание изображений...» добавляется эвристически — на втором ответе со статусом `processing`. Остальные логи пишутся только при смене статуса (`lastLoggedStatus`), дубли каждые 2 секунды исключены.
72. **`MessageService` зарегистрирован глобально** в [`app.config.ts`](client/src/app/app.config.ts:1): `<p-toast>` в драуере и компонент получают один и тот же инстанс. `providePrimeNG` его не предоставляет.
73. **PrimeFlex не установлен** (нет в `package.json`, добавлять зависимости без согласования нельзя), поэтому вместо `text-xs text-gray-500` — локальные стили `.log-line` (0.75rem, `#6b7280`). Блок логов — `div.logs-panel` c `max-height: 200px; overflow-y: auto`.

## Фронтенд: ошибки импорта в логах и UI (блок 8.5.4)

74. **`ImportJobError` — union из двух форм.** Бэкенд кладёт в `errors` либо ошибку строки XLSX (`{row, externalCode?, message}`), либо ошибку скачивания изображения (`{type: 'image', productId, url, message}` — [`ImportProductsHandler::downloadImages()`](src/MessageHandler/ImportProductsHandler.php:76)). Раньше интерфейс требовал `row`, которого у ошибок изображений нет. Теперь: `ImportJobRowError | ImportJobImageError`, различение — по наличию `url` (guard-функции `isImageError`/`isRowError` в [`import-job-status.model.ts`](client/src/app/models/import-job-status.model.ts:1)).
75. **`GET /api/import/{jobId}/status` отдаёт `error`.** Причина падения задачи писалась в JSON ([`markFailed()`](src/Service/ImportJobStatusStorage.php:61)), но в ответ API не попадала — пользователь видел только «Импорт завершён с ошибкой». Контракт расширен полем `error` (`null`, если задача не падала); тест `testStatusReturnsFailureReason` покрывает случай `failed`.
76. **Ошибки выводятся в лог поллинга один раз — на терминальном статусе.** `ImportService.logErrors()` вызывается из `tap` после `log()`, но пишет только при `completed`/`failed` и только один раз на `jobId` (`loggedErrors: Set<string>`). Иначе каждые 2 секунды дописывались бы одинаковые строки. Формат: сводка (`Скачивание изображений: N ошибок`), первые 10 позиций (`url — message` / `строка N — message`), затем `... и ещё M ошибок`. Лимит `MAX_ERROR_LINES = 10` обоснован `MAX_LOGS = 100` в панели.
77. **Служебные `Map`/`Set` сервиса чистятся по истории.** `pruneTracking()` вызывается при добавлении новой задачи в `history$` и удаляет записи для jobId за пределами последних `MAX_TRACKED_JOBS = 20`, иначе `loggedErrors`, `lastLoggedStatus` и `processingTicks` росли бы бесконечно при долгой сессии.
78. **Драуер показывает ошибки выбранного импорта.** Секция «Ошибки импорта» использует `p-table` (PrimeNG 21, шаблоны `#header`/`#body`) с колонками «Тип», «Строка / URL», «Ошибка». По умолчанию выбран последний импорт; клик по записи в истории переключает выбор (`selectedJobId: BehaviorSubject`). Тосты при `completed` с ошибками и при `failed` содержат первую ошибку / причину падения.

## Связь импорта со списком товаров (блок 8.5.5)

79. **Завершение импорта сообщается стору через action, а не через событие сервиса.** [`ImportService`](client/src/app/services/import.service.ts:1) диспатчит `ProductsActions.importCompleted({jobId, errors})` один раз на задачу (guard `finishedJobs: Set<string>`, чистится в `pruneTracking()` вместе с остальными служебными коллекциями). [`ProductsEffects.importCompleted$`](client/src/app/store/products/products.effects.ts:1) через `withLatestFrom(selectProductsQuery)` перезагружает список. Компонент списка про импорт не знает, NgRx остаётся единственным источником правды.
80. **После импорта список открывается на первой странице с текущими фильтрами и сортировкой.** Новые товары появляются в начале списка (сортировка `id` ASC), поэтому оставаться на прежней странице бессмысленно. `page: 1` задаётся в эффекте, `limit`/`name`/`price_min`/`price_max`/`sort`/`order` берутся из стора. `importCompleted` диспатчится и при `failed` — часть товаров могла записаться в БД до ошибки.
81. **`lastRequestedKey` синхронизируется со стором.** Эффект меняет `query` в сторе, минуя `requestQuery()`, поэтому [`ProductsPageComponent`](client/src/app/pages/products/products-page.component.ts:101) подписан на `selectProductsQuery` и при внешнем изменении обновляет локальные `currentQuery`/`lastRequestedKey`. Без этого устаревший ключ дедупликации блокировал бы следующий нужный запрос (например, возврат на страницу 3 после автоперезагрузки на страницу 1). Первое значение из стора игнорируется (`lastRequestedKey === null`), иначе первичный `onLazyLoad` не отправил бы запрос вовсе.

## Авторизация по JWT (блок 8.7)

82. **JWT — `firebase/php-jwt` v7.2.1, алгоритм HS256.** Версия 6.x заблокирована security advisory (`PKSA-y2cr-5h3j-g3ys`), поэтому взята актуальная 7.x. В v7: `JWT::encode(array $payload, string $key, string $alg)` и `JWT::decode(string $jwt, Key $key)`.
83. **Для HS256 секрет должен быть не короче 32 байт** — v7 проверяет длину ключа и бросает `DomainException`. Поэтому `JWT_SECRET` в `.env.example` — 41 символ, а в `config/settings.php` есть dev-дефолт (проект поднимается без `.env`, как и раньше). В проде секрет задаётся только через окружение.
84. **Токен живёт 24 часа (`JWT_TTL`, по умолчанию 86400).** Payload: `sub` (id пользователя), `email`, `iat`, `exp`. Верификация проверяет подпись и срок действия.
85. **`AuthMiddleware` навешан на всю группу `/api`**, публичные пути пропускает внутри: `/api/login`, `/api/health*`, `/api/docs`, `/api/docs.json`, плюс preflight `OPTIONS` (иначе CORS-запросы падали бы с 401). Реальная защита — только на бэкенде, guard на фронте не является защитой.
86. **`/api/docs` и `/api/docs.json` открыты** — так удобнее проверяющему на тестовом стенде. В проде их нужно закрыть (auth или ограничение по IP).
87. **`created_at` в `users` — `TIMESTAMPTZ NOT NULL`; значение ставит конструктор `User` (`DateTimeImmutable`), без DB-дефолта `now()`.** DBAL не поддерживает expression-дефолты: `DEFAULT 'now()'` — строка, а не вызов функции, и `migrations:diff` начинал генерировать лишние миграции. Без DB-дефолта схема синхронна маппингу (`migrations:diff` — `No changes detected`), поле всегда заполняется при вставке.
88. **Тестовый пользователь — отдельный скрипт [`bin/seed.php`](bin/seed.php:1), не миграция.** Миграции не создают данные; сидер идемпотентен (повторный запуск ничего не делает). Креды: `admin@admin` / `admin`, пароль хранится как `password_hash(PASSWORD_DEFAULT)`.
89. **Фронтенд: токен в `sessionStorage`** (`auth_token`) — живёт до закрытия вкладки; для тестового задания достаточно (в проде — httpOnly cookie). `AuthGuard` синхронный: проверяет только наличие токена, без запроса к API; просроченный токен отсекает бэкенд (401).
90. **`authInterceptor` пропускает `/api/login` и `/api/health`** (без `Authorization`) и добавляет `Bearer` ко всем остальным запросам. Шапка скрывается на `/login` (сигнал в [`AppComponent`](client/src/app/app.component.ts:1)), кнопка «Выйти» вызывает `AuthService.logout()` и редиректит на `/login`.
91. **`PasswordModule` PrimeNG работает через `ngModel`** — в [`LoginPageComponent`](client/src/app/pages/login/login-page.component.ts:1) подключён `FormsModule`; форма отправляется по `ngSubmit`.
