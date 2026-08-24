#!/usr/bin/env bash
set -euo pipefail

# OwnPay VPS bootstrap installer.
# The web installer remains responsible for database and administrator setup.

INSTALL_DIR="${OWNPAY_INSTALL_DIR:-$(pwd)}"
MIN_PHP_MAJOR=8
MIN_PHP_MINOR=3

if [[ "${EUID}" -ne 0 && "${INSTALL_DIR}" == /var/www/* ]]; then
  echo "Run this script as root when installing under /var/www."
  exit 1
fi

if ! command -v php >/dev/null 2>&1; then
  echo "PHP 8.3 or newer is required."
  exit 1
fi

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_OK="$(php -r 'echo version_compare(PHP_VERSION, "8.3.0", ">=") ? "yes" : "no";')"
if [[ "${PHP_OK}" != "yes" ]]; then
  echo "PHP 8.3 or newer is required; found ${PHP_VERSION}."
  exit 1
fi

required_extensions=(bcmath curl gd intl json mbstring openssl pdo_mysql zip)
missing=()
for extension in "${required_extensions[@]}"; do
  if ! php -m | grep -Eiq "^${extension}$"; then
    missing+=("${extension}")
  fi
done
if [[ "${#missing[@]}" -gt 0 ]]; then
  echo "Missing PHP extensions: ${missing[*]}"
  exit 1
fi

cd "${INSTALL_DIR}"
mkdir -p storage/{backups,cache,cron,framework,languages,logs,pdf,queue,sessions,temp} public/assets/uploads

if [[ ! -f .env && -f .env.example ]]; then
  cp .env.example .env
  echo "Created .env from .env.example. Review it before continuing."
fi

if [[ ! -f vendor/autoload.php ]]; then
  if ! command -v composer >/dev/null 2>&1; then
    echo "vendor/autoload.php is missing and Composer is not installed."
    exit 1
  fi
  composer install --no-dev --optimize-autoloader
fi

chmod -R u+rwX storage public/assets/uploads

echo "OwnPay files are ready."
echo "Open /install in your browser to configure the database and create the administrator."
