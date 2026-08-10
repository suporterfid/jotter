#!/bin/sh
set -eu

for directory in \
    storage/app/private \
    storage/app/vaults \
    storage/app/imports \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
do
    mkdir -p "$directory"
    chown -R www-data:www-data "$directory" 2>/dev/null || chmod -R 775 "$directory"
done

if [ ! -e public/storage ]; then
    php artisan storage:link
fi

exec "$@"
