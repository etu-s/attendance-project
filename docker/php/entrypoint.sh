#!/bin/sh
# Start-up shared by every AMS PHP container (php-fpm, horizon, scheduler,
# reverb, init). `init` prepares the databases and exits; anything else is
# exec'd as the container's main process.
set -eu
cd /var/www/html

: "${DB_DATABASE:=/var/www/data/central.sqlite}"
: "${TENANT_DB_DIRECTORY:=/var/www/data/tenants}"
export DB_DATABASE TENANT_DB_DIRECTORY

data_dir="$(dirname "$DB_DATABASE")"

# Named volumes start empty (or from an older image), so recreate the layout.
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs \
    "$TENANT_DB_DIRECTORY" "$data_dir"

# Without a configured APP_KEY, generate one once and keep it on the data volume,
# next to the databases whose encrypted values depend on it, so all containers
# and restarts share it. Losing the volume loses both together.
if [ -z "${APP_KEY:-}" ]; then
    key_file="$data_dir/app.key"
    if [ ! -s "$key_file" ]; then
        (umask 077 && php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$key_file")
    fi
    APP_KEY="$(cat "$key_file")"
    export APP_KEY
fi

# Each container has its own bootstrap/cache, built from its own environment.
# Cached in every environment: `docker compose exec` skips this script, so the
# cached config is how exec'd artisan commands get the generated APP_KEY.
php artisan config:cache --no-ansi -q
php artisan route:cache --no-ansi -q
php artisan event:cache --no-ansi -q

if [ "${1:-}" = "init" ]; then
    [ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"
    # Every step is idempotent: plans are upserted, the local demo tenant and
    # super admin are created only once (APP_ENV=local only), and tenants:seed
    # only adds missing roles, permissions and settings.
    php artisan migrate --seed --force --no-ansi
    php artisan tenants:migrate --force --no-ansi
    php artisan tenants:seed --force --no-ansi
    exit 0
fi

exec "$@"
