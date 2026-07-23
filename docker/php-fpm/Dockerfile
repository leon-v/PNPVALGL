FROM php:8.5-fpm

RUN apt update && apt install -y --no-install-recommends \
    # Build tools
    git ca-certificates gcc make pkg-config \
    # PHP extension build deps
    libpq-dev libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libicu-dev \
    # Runtime
    jq procps zip unzip \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ─────────────────────────────────────────────────────────

RUN docker-php-ext-configure gd --with-freetype --with-jpeg &&\
    docker-php-ext-install -j$(nproc) pdo_pgsql pgsql bcmath gd pcntl intl &&\
    pecl install xdebug redis zip &&\
    docker-php-ext-enable xdebug redis zip &&\
    cd /tmp && git clone --depth 1 https://github.com/krakjoe/uopz.git &&\
    cd uopz && phpize && ./configure && make -j$(nproc) && make install &&\
    docker-php-ext-enable uopz && rm -rf /tmp/uopz

# ── Application setup ──────────────────────────────────────────────────────

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN groupadd -g 1000 app && useradd -u 1000 -g app -m -s /bin/bash app

# App log directory — PHP-FPM writes its daemon error log here.
# Shared with the Alloy container via bind mount.
RUN mkdir -p /var/log/php-fpm && chown app:app /var/log/php-fpm

WORKDIR /var/www
