FROM composer:2.8.4-php8.2
RUN addgroup -g 1000 laravel && adduser -G laravel -g laravel -s /bin/sh -D laravel
USER laravel
WORKDIR /var/www/html
ENTRYPOINT [ "composer"]
CMD [ "--help" ]