FROM php:8.3-apache

# Apache document root = public/
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN a2enmod rewrite headers expires deflate

# System dependencies for PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
        libargon2-dev \
        libcap2-bin \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libssl-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
    && rm -rf /var/lib/apt/lists/* \
    && setcap 'cap_net_bind_service=+ep' /usr/sbin/apache2

# PHP extensions required by OwnPay's installer/runtime
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis

# Composer 2
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Point Apache at public/
RUN sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf /etc/apache2/sites-available/*.conf

WORKDIR /var/www/html

# Runtime permission handling for bind-mounted source
RUN printf '%s\n' \
        '#!/bin/sh' \
        'set -e' \
        'APP_DIR=/var/www/html' \
        'chgrp www-data "$APP_DIR" 2>/dev/null || true' \
        'chmod g+w "$APP_DIR" 2>/dev/null || true' \
        'if [ -d "$APP_DIR/storage" ]; then chown -R www-data:www-data "$APP_DIR/storage"; fi' \
        'mkdir -p /var/run/apache2 /var/log/apache2 /var/lock/apache2' \
        'chown -R www-data:www-data /var/run/apache2 /var/log/apache2 /var/lock/apache2' \
        'exec setpriv --reuid=www-data --regid=www-data --clear-groups apache2-foreground' \
    > /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
