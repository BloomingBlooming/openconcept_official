#!/bin/sh
set -eu

mkdir -p /var/www/html/storage /var/www/html/published
if [ ! -f /var/www/html/storage/.htaccess ]; then
    cp /opt/openconcept-seed/storage.htaccess /var/www/html/storage/.htaccess
fi
if [ ! -f /var/www/html/published/.htaccess ]; then
    cp /opt/openconcept-seed/published.htaccess /var/www/html/published/.htaccess
fi
chown -R www-data:www-data /var/www/html/storage /var/www/html/published

exec docker-php-entrypoint "$@"
