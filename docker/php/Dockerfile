# syntax=docker/dockerfile:1

###############################################
# Stage 1: builder — расширения + composer deps
###############################################
FROM php:8.3-fpm AS builder

# ext-xml, ext-dom, ext-simplexml, ext-mbstring, ext-fileinfo уже есть в базовом образе.
RUN apt-get update && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        libpq-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        librabbitmq-dev \
        git \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        zip \
        gd \
        intl \
        bcmath \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.* ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts

###############################################
# Stage 2: runtime — только то, что нужно в проде
###############################################
FROM php:8.3-fpm AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq5 \
        libzip5 \
        libpng16-16 \
        libjpeg62-turbo \
        libfreetype6 \
        librabbitmq4 \
        libicu76 \
        libonig5 \
        libxml2 \
        unzip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

COPY --from=builder --chown=www-data:www-data /var/www/html/vendor ./vendor

COPY --chown=www-data:www-data . .

RUN mkdir -p var && chown -R www-data:www-data var

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
