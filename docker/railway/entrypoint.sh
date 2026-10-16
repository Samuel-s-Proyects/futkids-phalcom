#!/bin/sh
set -eu
mkdir -p /var/www/html/var/cache /var/www/html/var/session /var/www/html/var/log /var/www/html/public/uploads/players
chown -R www-data:www-data /var/www/html/var /var/www/html/public/uploads
# The database may still be starting on the first deploy; retry migrations briefly.
attempt=1
until php /var/www/html/scripts/migrate.php; do
    [ "$attempt" -ge 10 ] && exit 1
    echo "Base de datos no disponible, reintentando ($attempt/10)..."
    attempt=$((attempt + 1))
    sleep 3
done
php /var/www/html/scripts/seed.php
sed "s/__PORT__/${PORT:-8080}/" /etc/nginx/futkids.conf.template > /etc/nginx/conf.d/default.conf
php-fpm -D
exec nginx -g 'daemon off;'
