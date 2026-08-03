ARG PHP_VERSION=8.2
FROM composer:2 AS composer

FROM php:${PHP_VERSION}-apache

LABEL org.opencontainers.image.title="apache-web" \
      org.opencontainers.image.description="Apache + PHP-Apache para NOVA, proxy unico de servicios internos"

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        curl \
        unzip \
        git \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        mbstring \
        intl \
        bcmath \
        pdo_mysql \
        zip \
        opcache

RUN a2enmod rewrite headers expires proxy proxy_http proxy_wstunnel

RUN rm -f /etc/apache2/sites-enabled/000-default.conf \
    && rm -f /etc/apache2/sites-available/000-default.conf \
    && mkdir -p /etc/apache2/vhosts.d

RUN mkdir -p /var/www/_health && printf 'OK\n' > /var/www/_health/index.html

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

ARG PUID=1001
ARG PGID=1002
RUN groupmod -g "${PGID}" www-data \
    && usermod -u "${PUID}" -g "${PGID}" www-data

WORKDIR /var/www/html

EXPOSE 80
