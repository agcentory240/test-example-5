# Импорт товаров из XLSX в мини-CRM

Сервис принимает XLSX-файл с товарами, асинхронно разбирает его через очередь, сохраняет товары, атрибуты и изображения в PostgreSQL и показывает каталог в веб-интерфейсе с пагинацией, поиском, фильтрами и сортировкой.

Экспорт товаров в XLSX в текущей реализации не предусмотрен — задание покрывает только импорт.

## Стек

| Слой | Технологии |
|---|---|
| Backend | Slim 4, Doctrine ORM 3 / DBAL 4, Doctrine Migrations |
| Очереди | Symfony Messenger 7 + RabbitMQ (AMQP transport) |
| БД | PostgreSQL 16 |
| API-документация | swagger-php (OpenAPI) + Swagger UI |
| Frontend | Angular 21 (standalone, zoneless), PrimeNG 21, NgRx 21 |
| Инфраструктура | Docker + Docker Compose, Nginx, PHP 8.3, pgAdmin |

## Требования

- Docker и Docker Compose (v2 `docker compose` или v1 `docker-compose`)
- Node.js 20+ и npm (для локального запуска фронтенда)
- Git

## Быстрый старт

### Вариант 1 — автоматический (рекомендуется)

```bash
chmod +x start.sh
./start.sh
```

Скрипт [`start.sh`](start.sh:1):

1. проверяет наличие `docker`, `node`, `npm`;
2. создаёт `.env` из [`.env.example`](.env.example:1), если файла ещё нет;
3. поднимает контейнеры (`docker compose up -d --build`);
4. ждёт готовности PostgreSQL (`pg_isready`, до 60 секунд);
5. перезапускает Nginx (кэширует IP контейнера `app`);
6. применяет миграции Doctrine;
7. создаёт тестового пользователя `admin@admin` / `admin`;
8. устанавливает зависимости фронтенда (если нет `node_modules`) и запускает `ng serve`.

### Вариант 2 — пошагово

```bash
cp .env.example .env
make up
make migrate
make seed
cd client && npm install && npm start
```

## После запуска

| Сервис | Адрес |
|---|---|
| Фронтенд | http://localhost:4200 |
| API | http://localhost:8080 |
| Swagger UI | http://localhost:8080/api/docs |
| RabbitMQ UI | http://localhost:15672 (guest / guest) |
| pgAdmin | http://localhost:5050 |
| PostgreSQL | `localhost:5432` (app / secret, БД `app`) |

Тестовый пользователь: **`admin@admin`** / **`admin`**.

## Архитектура

Поток импорта:

```
XLSX → POST /api/import → сохранение в var/uploads/imports/{jobId}.xlsx
     → статус queued + сообщение ImportProductsMessage в RabbitMQ (202 + jobId)
     → воркер (bin/worker.php): парсинг XLSX
     → upsert товаров, атрибутов и изображений в PostgreSQL (транзакция на товар)
     → скачивание картинок в public/uploads/products/...
     → обновление статуса (completed / failed) в var/imports/{jobId}.json
     → фронтенд polling GET /api/import/{jobId}/status каждые 2 с
     → после completed NgRx перезагружает список товаров
```

- **API** — Slim 4, маршруты в [`config/routes.php`](config/routes.php:17), сборка зависимостей в [`config/container.php`](config/container.php:1).
- **Очередь** — [`config/messenger.php`](config/messenger.php:1) (standalone Messenger без Symfony Framework), воркер — отдельный контейнер `worker`.
- **Кэш/статусы** — файлы в `var/`: `var/imports/` (статусы), `var/uploads/imports/` (загруженные файлы), `var/rate_limit/` (счётчики), `var/logs/`.
- **Фронтенд** — Angular 21 standalone (`bootstrapApplication`, lazy loading), NgRx только для списка товаров, PrimeNG Aura.

## Импорт: как пользоваться

1. Войдите под `admin@admin` / `admin`.
2. Нажмите **«Импорт»** в шапке — откроется драуер.
3. Выберите `.xlsx`-файл и нажмите **«Загрузить»**.
4. Файл уходит в очередь: API отвечает `202` с `jobId`, в драуере идут логи поллинга каждые 2 секунды.
5. Тосты по завершении: зелёный — успех, жёлтый — завершено с ошибками, красный — импорт провален.
6. Список товаров автоматически перезагружается после статуса `completed`.
7. Ошибки скачивания картинок и ошибки строк видны в логах поллинга и в блоке **«Ошибки импорта»** драуера (с выбором конкретного job из истории импортов).

Ограничения импорта: только расширение `.xlsx`, лимит размера — `IMPORT_MAX_FILE_SIZE` (по умолчанию 10 МБ), не более 10 загрузок в час с одного IP.

## API

| Метод | Путь | Описание |
|---|---|---|
| POST | `/api/login` | JWT-логин (`{email, password}`) |
| GET | `/api/products` | Список: `page`, `limit`, `name`, `price_min`, `price_max`, `sort`, `order` |
| GET | `/api/products/{id}` | Карточка товара: атрибуты, изображения, `discount` |
| DELETE | `/api/products` | Очистка всех товаров и файлов (`204`, тестовый функционал) |
| POST | `/api/import` | Загрузка XLSX (`202` + `jobId`) |
| GET | `/api/import/{jobId}/status` | Статус импорта: `queued` / `processing` / `completed` / `failed` |
| GET | `/api/health` | Health check API |
| GET | `/api/health/db` | Проверка соединения с БД |
| GET | `/api/docs` | Swagger UI |
| GET | `/api/docs.json` | OpenAPI-спека |

Служебные эндпоинты тестового стенда: `POST /api/import/parse-test` (только парсинг XLSX, без записи в БД) и `GET /api/test-dispatch` (проверка связки Messenger + RabbitMQ).

## Авторизация и безопасность

- **JWT HS256** ([`src/Service/AuthService.php`](src/Service/AuthService.php:1)), TTL — `JWT_TTL` (по умолчанию 24 часа).
- [`AuthMiddleware`](src/Middleware/AuthMiddleware.php:1) закрывает весь `/api`, кроме `/api/login`, `/api/health*`, `/api/docs*` и preflight `OPTIONS`.
- На фронтенде токен хранится в `sessionStorage` ([`AuthService`](client/src/app/services/auth.service.ts:6)); [`AuthGuard`](client/src/app/guards/auth.guard.ts:9) синхронно проверяет наличие токена, [`authInterceptor`](client/src/app/interceptors/auth.interceptor.ts:1) добавляет `Authorization: Bearer`.
- `/api/docs` **открыт** на тестовом стенде; в продакшене закрыть (auth или ограничение по IP).
- Файлы `public/uploads/products/*` отдаются Nginx статикой **без авторизации**; в продакшене — подписанные URL или PHP-контроллер с проверкой доступа.

## Переменные окружения

Все переменные с комментариями — в [`.env.example`](.env.example:1): `APP_ENV`, `APP_DEBUG`, `CORS_ALLOWED_ORIGINS`, `RATE_LIMIT_MAX`, `RATE_LIMIT_WINDOW`, `JWT_SECRET`, `JWT_TTL`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_VHOST`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD`, `RABBITMQ_EXCHANGE`, `RABBITMQ_QUEUE`, `IMPORT_MAX_FILE_SIZE`, `PGADMIN_DEFAULT_EMAIL`, `PGADMIN_DEFAULT_PASSWORD`.

## Команды

```bash
make up       # поднять контейнеры (сборка образов)
make down     # остановить контейнеры
make migrate  # применить миграции Doctrine
make seed     # создать пользователя admin@admin / admin
make test     # PHPUnit
make stan     # PHPStan
make cs-fix   # PHP CS Fixer
make e2e      # Playwright (smoke)
```

Полный список целей — в [`Makefile`](Makefile:1).

## Тесты

```bash
make test    # PHPUnit: Unit + Integration (SQLite in-memory)
make stan    # PHPStan
make cs-fix  # PHP CS Fixer
make e2e     # Playwright smoke (нужны поднятый бэкенд, миграции и сид)
```

E2E-сценарий ([`client/e2e/smoke.spec.ts`](client/e2e/smoke.spec.ts:1)): логин → `/products` → импорт `products-no-images.xlsx` → товар появляется в списке.

## Решения и допущения

Архитектурные решения и ограничения описаны в [`DECISIONS.md`](DECISIONS.md:1).

## Ограничения

- Дедупликация изображений по содержимому не реализована (ТЗ не требует); дедупликация — только по `url` в рамках товара.
- Файлы изображений доступны напрямую без токена (тестовый стенд).
- `/api/docs` и `/api/docs.json` открыты на тестовом стенде.
- E2E-покрытие — один smoke-сценарий (Playwright).
- Экспорт товаров в XLSX не реализован.

## Структура проекта

```
src/           # Slim: контроллеры, сервисы, сущности, middleware, DTO
client/        # Angular 21 + PrimeNG + NgRx (+ Playwright e2e)
config/        # контейнер, настройки, маршруты, Messenger, migrations
migrations/    # Doctrine migrations
tests/         # PHPUnit (Unit + Integration + Support)
bin/           # worker.php, seed.php, doctrine
docker/        # Dockerfile, php.ini, конфиг Nginx
public/        # точка входа index.php + статика (uploads)
var/           # uploads, imports, logs, rate_limit (создаётся при работе)
```
