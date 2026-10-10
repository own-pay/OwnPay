# Build environment for Anthropic's OSS Scanner (https://red.anthropic.com/oss-scanner).
#
# It mirrors the jobs in .github/workflows/ci.yml: PHP 8.3 with the extensions CI
# installs, Node 24, Composer and npm dependencies, the same static checks, and the
# same PHPUnit and Vitest suites.
#
# The scanner builds this with the repository as the build context, so `COPY . /src`
# places the checkout at /src. Network access is available while this image builds and
# is removed afterwards, so everything a scan may need is fetched here.

FROM php:8.3-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# The extensions CI installs: zip, mbstring, pdo, pdo_mysql, bcmath, gd, intl.
# json, mbstring, pdo and openssl are already built into the official image.
# Versions are deliberately not pinned: this image is long-lived and pinning Debian
# packages would leave it unable to install at all once bookworm moves on.
# hadolint ignore=DL3008
RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates curl git gnupg unzip \
      libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# Node 24, matching the frontend-lint and frontend-test CI jobs.
# hadolint ignore=DL3008
RUN curl -fsSL -o /tmp/nodesource_setup.sh https://deb.nodesource.com/setup_24.x \
    && bash /tmp/nodesource_setup.sh \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -f /tmp/nodesource_setup.sh \
    && rm -rf /var/lib/apt/lists/*

# Optional. A database server lets a scan exercise the queries in src/Repository and
# the migrations against a real engine while offline. Debian ships MariaDB here, not
# the MySQL 8.0 the phpunit CI job uses. The scan is still useful without it, so a
# failure to install is reported rather than failing the build.
# hadolint ignore=DL3008
RUN apt-get update \
    && (apt-get install -y --no-install-recommends default-mysql-server \
        || echo "WARNING: no database server installed; the DB-backed suites stay skipped") \
    && rm -rf /var/lib/apt/lists/*

# Composer is not part of the official PHP image; take the binary from its own image so
# no version is pinned to a Debian package that will eventually move on.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# scanner contract: the checkout lives inside the image, at /src
COPY . /src
WORKDIR /src

# Install from composer.lock into an empty tree, so the image carries exactly the locked
# dependencies whatever the checkout happened to contain.
RUN rm -rf vendor \
    && composer install --prefer-dist --no-progress --no-interaction

RUN npm ci --no-audit --no-fund

# These checks are green on main, so a failure here is a real regression and is worth
# failing the build over. The lint paths are the ones ci.yml uses, plus tests.
RUN vendor/bin/parallel-lint --no-progress src config modules templates/install public/index.php tests \
    && vendor/bin/phpstan analyse --no-progress \
    && vendor/bin/twig-cs-fixer lint templates \
    && npm run lint

# The test suites have pre-existing failures on main (see .oss-scanner/threat_model.md),
# so neither may fail the build; the failures are still visible in the scanner's log.
RUN (vendor/bin/phpunit --no-coverage || echo "WARNING: phpunit reported failures") \
    && (npm test || echo "WARNING: the front-end suite reported failures")
