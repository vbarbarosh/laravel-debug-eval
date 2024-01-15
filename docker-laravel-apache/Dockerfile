FROM php:8.1-apache

RUN apt-get update
RUN apt-get install -y wget zip unzip

RUN wget https://raw.githubusercontent.com/composer/getcomposer.org/76a7060ccb93902cd7576b67264ad91c8a2700e2/web/installer -O - -q | php
RUN mv composer.phar /usr/bin/composer

RUN composer create-project laravel/laravel /app
WORKDIR /app
RUN sed -i 's!^}$!,"repositories": [{"type":"path","url":"./laravel-debug-eval"}]}!' composer.json
COPY fs /

RUN composer require predis/predis sentry/sentry-laravel endyjasmi/cuid
RUN composer require vbarbarosh/laravel-debug-eval
RUN php artisan key:generate --force
RUN chown -R www-data:www-data /app/storage
