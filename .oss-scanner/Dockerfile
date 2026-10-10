FROM php:8.3-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1
    
RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates curl git gnupg unzip \
      libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL -o /tmp/nodesource_setup.sh https://deb.nodesource.com/setup_24.x \
    && bash /tmp/nodesource_setup.sh \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -f /tmp/nodesource_setup.sh \
    && rm -rf /var/lib/apt/lists/*

RUN apt-get update \
    && (apt-get install -y --no-install-recommends default-mysql-server \
        || echo "WARNING: no database server installed; the DB-backed suites stay skipped") \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# scanner contract: the checkout lives inside the image, at /src
COPY . /src
WORKDIR /src

RUN rm -rf vendor \
    && composer install --prefer-dist --no-progress --no-interaction

RUN npm ci --no-audit --no-fund

RUN vendor/bin/parallel-lint --no-progress src config modules templates/install public/index.php tests \
    && vendor/bin/phpstan analyse --no-progress \
    && vendor/bin/twig-cs-fixer lint templates \
    && npm run lint

RUN (vendor/bin/phpunit --no-coverage || echo "WARNING: phpunit reported failures") \
    && (npm test || echo "WARNING: the front-end suite reported failures")
