FROM node:22-alpine AS shopify
WORKDIR /shopify-app
COPY shopify-app/package*.json ./
RUN npm ci --no-audit --no-fund
COPY shopify-app/ ./
ENV NODE_OPTIONS=--max-old-space-size=512
RUN npm run build

FROM php:8.3-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libzip-dev libicu-dev ffmpeg unzip git ca-certificates \
 && docker-php-ext-install -j1 pdo_mysql mbstring zip intl bcmath pcntl opcache \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY backend/ ./
COPY --from=shopify /backend/public/shopify ./public/shopify
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=512M
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public bootstrap/cache \
 && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
 && chown -R www-data:www-data storage bootstrap/cache
COPY deployment/php.ini /usr/local/etc/php/conf.d/bs-ads.ini
COPY deployment/fpm.conf /usr/local/etc/php-fpm.d/zz-bs-ads.conf
CMD ["php-fpm"]
