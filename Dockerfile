# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 — build llama-server (CPU-only, portable across amd64/arm64)
# ---------------------------------------------------------------------------
FROM php:8.4-cli-bookworm AS llama-builder

ARG LLAMA_CPP_TAG=v0.3.0

RUN apt-get update \
 && apt-get install -y --no-install-recommends cmake g++ git ca-certificates \
 && rm -rf /var/lib/apt/lists/* \
 && git clone --depth 1 --branch "${LLAMA_CPP_TAG}" https://github.com/ggml-org/llama.cpp /src/llama.cpp \
 && cmake -B /src/llama.cpp/build -S /src/llama.cpp \
      -DCMAKE_BUILD_TYPE=Release \
      -DGGML_NATIVE=OFF \
      -DBUILD_SHARED_LIBS=OFF \
 && cmake --build /src/llama.cpp/build --config Release -j"$(nproc)" --target llama-server

# ---------------------------------------------------------------------------
# Stage 2 — runtime: nginx + php-fpm + llama-server + cron + pg client
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-bookworm

# PostgreSQL APT repo (pgdg) — postgresql-client-17 is version-matched to the
# postgres:17 server so pg_dump -Fc / pg_restore stay compatible (Debian
# bookworm's stock client is PG 15).
RUN apt-get update \
 && apt-get install -y --no-install-recommends curl ca-certificates gnupg \
 && install -m 0755 -d /etc/apt/keyrings \
 && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
      | gpg --dearmor -o /etc/apt/keyrings/pgdg.gpg \
 && echo "deb [signed-by=/etc/apt/keyrings/pgdg.gpg] http://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" \
      > /etc/apt/sources.list.d/pgdg.list \
  && apt-get update \
 && apt-get install -y --no-install-recommends nginx cron postgresql-client-17 \
    libpq-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev libzip-dev zip \
 && rm -rf /var/lib/apt/lists/*

# Composer is not in the base image, and the app needs PHP extensions the base
# image lacks: ext-pdo_pgsql + ext-gd (composer.json) and ext-zip (locked deps).
RUN curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
 && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet \
 && rm /tmp/composer-setup.php \
 && docker-php-ext-install pdo_pgsql gd zip

COPY --from=llama-builder /src/llama.cpp/build/bin/llama-server /usr/local/bin/llama-server

WORKDIR /var/www/atr
COPY . .
COPY docker/php/99-atr.ini /usr/local/etc/php/conf.d/99-atr.ini

RUN composer install --no-dev --no-interaction --optimize-autoloader \
 && mkdir -p storage/uploads storage/backups storage/logs storage/reports \
             storage/labels storage/sessions storage/run storage/models \
 && chown -R www-data:www-data storage \
  && rm -f /etc/nginx/sites-enabled/default \
  && rm -f /usr/local/etc/php-fpm.d/zz-docker.conf \
  && ln -s /var/www/atr/docker/nginx.conf /etc/nginx/sites-enabled/atr \
 && install -m 0644 docker/cron/atr /etc/cron.d/atr \
 && install -m 0755 docker/entrypoint.sh /usr/local/bin/atr-entrypoint

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 \
  CMD php -r 'exit(strpos((string)@file_get_contents("http://127.0.0.1:8080/healthz"), "\"status\":\"ok\"") === false ? 1 : 0);'

ENTRYPOINT ["atr-entrypoint"]
