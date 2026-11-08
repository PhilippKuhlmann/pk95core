#!/bin/sh
# Starts PK95 Core: prepares the persistent storage volume, the app key, the database and
# access to the Docker socket, then hands over to supervisord (nginx, php-fpm, scheduler,
# queue worker).
set -e
cd /app

# The storage volume may start empty (bind mount) – recreate Laravel's layout.
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
         storage/framework/sessions storage/framework/views storage/logs

# APP_KEY encrypts the per-app service tokens. It must never be baked into the image
# and must survive container re-creation: keep it in the volume.
if [ -z "${APP_KEY}" ]; then
    if [ ! -s storage/app/private/app.key ]; then
        echo "==> Erzeuge neuen Anwendungsschlüssel"
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > storage/app/private/app.key
        chmod 600 storage/app/private/app.key
    fi
    APP_KEY="$(cat storage/app/private/app.key)"
    export APP_KEY
fi

# Docker socket: php-fpm, scheduler and queue run as www-data. Give www-data the group
# that owns the socket (its GID differs from host to host).
SOCKET="${PK95_DOCKER_SOCKET:-/var/run/docker.sock}"
if [ -S "$SOCKET" ]; then
    gid="$(stat -c %g "$SOCKET")"
    if [ "$gid" = "0" ]; then
        # root-owned socket without docker group: allow the group of www-data instead.
        chgrp www-data "$SOCKET" && chmod g+rw "$SOCKET"
    else
        group="$(getent group "$gid" | cut -d: -f1)"
        if [ -z "$group" ]; then
            group=dockersock
            addgroup -g "$gid" "$group"
        fi
        addgroup www-data "$group" 2>/dev/null || true
    fi
    echo "==> Docker-Socket eingebunden ($SOCKET)"
else
    echo "!!  Kein Docker-Socket unter $SOCKET – Apps können nicht gestartet werden"
fi

# Data root for backups (same path as on the LXC, see docker-compose.yml)
if [ -d "${PK95_DATA_DIR:-/opt/pk95}" ]; then
    mkdir -p "${PK95_DATA_DIR:-/opt/pk95}/backups"
    chown www-data:www-data "${PK95_DATA_DIR:-/opt/pk95}/backups"
fi

# php-fpm and the queue workers read the environment from .env, not from the shell.
: > .env
for key in APP_NAME APP_ENV APP_DEBUG APP_URL APP_KEY APP_LOCALE LOG_CHANNEL LOG_LEVEL \
           DB_CONNECTION DB_DATABASE SESSION_DRIVER SESSION_LIFETIME SESSION_SECURE_COOKIE QUEUE_CONNECTION \
           DB_QUEUE_RETRY_AFTER CACHE_STORE TRUSTED_PROXIES TZ \
           PK95_CATALOG PK95_DATA_DIR PK95_DOCKER_SOCKET PK95_DOCKER_API PK95_NETWORK PK95_CORE_URL \
           PK95_HOST_ADDRESS PK95_PULL_TIMEOUT PK95_HEALTH_TIMEOUT PK95_APP_TIMEOUT; do
    eval "value=\${$key-}"
    [ -n "$value" ] && printf '%s="%s"\n' "$key" "$value" >> .env
done
chmod 640 .env && chown root:www-data .env

[ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"

php artisan package:discover --no-interaction >/dev/null
echo "==> Migrationen"
php artisan migrate --force --no-interaction
php artisan pk95:recover --no-interaction || true
# First run (no account yet): one-time code for the setup page, file 0600 in the volume.
php artisan pk95:setup-token --no-interaction
php artisan config:cache --no-interaction >/dev/null
php artisan route:cache --no-interaction >/dev/null
php artisan view:cache --no-interaction >/dev/null

chown -R www-data:www-data storage bootstrap/cache
chmod 600 storage/app/private/app.key 2>/dev/null || true
chmod 600 storage/app/private/setup.token 2>/dev/null || true

echo "==> PK95 Core läuft auf Port 8080"
exec supervisord -c /etc/supervisord.conf
