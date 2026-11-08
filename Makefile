DC := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")

.PHONY: up down ps logs migrate test stan cs-fix

up:
	$(DC) up -d --build

down:
	$(DC) down

ps:
	$(DC) ps

logs:
	$(DC) logs -f --tail=100

migrate:
	$(DC) exec app php vendor/bin/doctrine-migrations migrate --no-interaction

test:
	$(DC) exec app vendor/bin/phpunit

stan:
	$(DC) exec app vendor/bin/phpstan analyse

cs-fix:
	$(DC) exec app vendor/bin/php-cs-fixer fix
