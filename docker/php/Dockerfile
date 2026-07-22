FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        libicu-dev \
        libonig-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        mbstring \
        pdo_mysql \
        zip \
    && a2enmod headers rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/apache/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/conf.d/jotter.ini /usr/local/etc/php/conf.d/jotter.ini
COPY docker/php/entrypoint.sh /usr/local/bin/jotter-entrypoint

RUN chmod +x /usr/local/bin/jotter-entrypoint

ENTRYPOINT ["jotter-entrypoint"]
CMD ["apache2-foreground"]
