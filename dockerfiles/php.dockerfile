FROM php:8.2-fpm-alpine
WORKDIR /var/www/html
COPY src .

RUN apk add --no-cache mysql-client msmtp perl wget procps shadow libzip libpng libjpeg-turbo libwebp freetype icu ca-certificates postgresql-libs
RUN apk add --no-cache --virtual build-essentials \
    icu-dev icu-libs zlib-dev g++ make automake autoconf libzip-dev libxml2-dev oniguruma-dev \
    libpng-dev libwebp-dev libjpeg-turbo-dev freetype-dev postgresql-dev && \
    docker-php-ext-configure gd --enable-gd --with-freetype --with-jpeg --with-webp && \
    docker-php-ext-install gd && \
    docker-php-ext-install mysqli && \
    docker-php-ext-install pdo_mysql && \
    docker-php-ext-install pgsql pdo_pgsql && \
    docker-php-ext-install intl && \
    docker-php-ext-install bcmath && \
    docker-php-ext-install opcache && \
    docker-php-ext-install exif && \
    docker-php-ext-install zip && \
    docker-php-ext-install soap && \
    docker-php-ext-install mbstring && \
    apk del build-essentials && rm -rf /usr/src/php*

RUN apk add --no-cache pcre-dev $PHPIZE_DEPS && \
    pecl install redis && \
    docker-php-ext-enable redis.so

RUN addgroup -g 1000 laravel && adduser -G laravel -g laravel -s /bin/sh -D laravel
RUN chown -R laravel /var/www/html
USER laravel
