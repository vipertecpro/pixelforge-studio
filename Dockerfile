# syntax=docker/dockerfile:1
#
# One image that serves the app (FrankenPHP, a PHP application server with
# Caddy built in), sends images and processes webhook events (queue worker) and
# runs the polling fallback (scheduler). It listens on $PORT over plain HTTP,
# because the host terminates TLS in front of it.

# ---- Build the Vue app. The Wayfinder plugin runs artisan, so this stage needs PHP too.
FROM dunglas/frankenphp:1.13-php8.5-bookworm AS assets

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:26-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:26-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts

COPY package.json package-lock.json ./
RUN npm ci --audit false --fund false

COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views \
    && composer dump-autoload --no-dev --optimize \
    && npm run build

# ---- The image that runs.
FROM dunglas/frankenphp:1.13-php8.5-bookworm AS app

RUN install-php-extensions pdo_pgsql pcntl opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --optimize-autoloader

COPY . .
COPY --from=assets /app/public/build public/build
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --optimize \
    && rm -rf /root/.composer /root/.cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

EXPOSE 10000
ENTRYPOINT ["entrypoint"]
