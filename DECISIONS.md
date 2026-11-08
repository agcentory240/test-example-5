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

11. **`worker` падает до появления `bin/console`** (блок 4). Это ожидаемо: `restart: unless-stopped` перезапускает контейнер, пока консьюмер не реализован.

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
