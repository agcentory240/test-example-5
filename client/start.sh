#!/usr/bin/env bash
set -euo pipefail

# Цвета для вывода
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log()  { echo -e "${GREEN}[✓]${NC} $1"; }
warn() { echo -e "${YELLOW}[!]${NC} $1"; }
err()  { echo -e "${RED}[✗]${NC} $1"; }

# 1. Проверка зависимостей
log "Проверка зависимостей..."
command -v docker >/dev/null 2>&1 || { err "Docker не установлен"; exit 1; }
command -v node >/dev/null 2>&1   || { err "Node.js не установлен"; exit 1; }
command -v npm >/dev/null 2>&1    || { err "npm не установлен"; exit 1; }

# Авто-детект docker compose / docker-compose
if docker compose version >/dev/null 2>&1; then
  DC="docker compose"
else
  DC="docker-compose"
fi
log "Используется: $DC"

# 2. .env
if [ ! -f .env ]; then
  if [ -f .env.example ]; then
    cp .env.example .env
    log ".env создан из .env.example"
  else
    warn ".env.example не найден — пропускаю"
  fi
else
  log ".env уже существует"
fi

# 3. Поднять Docker
log "Поднимаю контейнеры (это может занять время при первой сборке)..."
$DC up -d --build

# 4. Дождаться healthy БД
log "Жду готовности PostgreSQL..."
for i in $(seq 1 30); do
  if $DC exec -T db pg_isready -U "${DB_USER:-app}" -d "${DB_NAME:-app}" >/dev/null 2>&1; then
    log "PostgreSQL готов"
    break
  fi
  if [ "$i" -eq 30 ]; then
    err "PostgreSQL не поднялся за 60 секунд"
    exit 1
  fi
  sleep 2
done

# 5. Перезапустить nginx (кэширует IP app)
log "Перезапускаю nginx..."
$DC restart nginx >/dev/null

# 6. Миграции
log "Применяю миграции..."
$DC exec -T app php bin/doctrine migrations:migrate --no-interaction

# 7. Сидер
log "Создаю тестового пользователя admin@admin..."
$DC exec -T app php bin/seed.php

# 8. Фронтенд
log "Устанавливаю зависимости фронтенда..."
cd client
if [ ! -d node_modules ]; then
  npm install
else
  log "node_modules уже есть — пропускаю npm install"
fi

log "Запускаю Angular dev-сервер..."
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Бэкенд:  http://localhost:8080${NC}"
echo -e "${GREEN}  Swagger: http://localhost:8080/api/docs${NC}"
echo -e "${GREEN}  Фронт:   http://localhost:4200${NC}"
echo -e "${GREEN}  Логин:   admin@admin / admin${NC}"
echo -e "${GREEN}========================================${NC}"
npm start