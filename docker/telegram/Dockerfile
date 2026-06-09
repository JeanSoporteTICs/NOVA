FROM php:8.2-cli-alpine

RUN apk add --no-cache bash ca-certificates curl libcurl oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS curl-dev oniguruma-dev \
    && docker-php-ext-install curl mbstring pcntl \
    && apk del .build-deps

WORKDIR /app

CMD ["php", "telegram/bin/service.php"]
