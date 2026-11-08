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
