#!/bin/sh
set -e

MOODLE_ROOT="${MOODLE_ROOT:-/var/www/moodle}"
CONFIG="${MOODLE_ROOT}/config.php"

# The named volume starts root-owned.
mkdir -p /var/moodledata
chown www-data:www-data /var/moodledata

echo "[entrypoint] waiting for postgres at ${POSTGRES_HOST}..."
until php -r '
    $c = @pg_connect(sprintf(
        "host=%s port=5432 dbname=%s user=%s password=%s",
        getenv("POSTGRES_HOST"), getenv("POSTGRES_DB"),
        getenv("POSTGRES_USER"), getenv("POSTGRES_PASSWORD")
    ));
    exit($c ? 0 : 1);
' 2>/dev/null; do
    sleep 2
done
echo "[entrypoint] postgres is up"

# config.php sits in the container filesystem, not a volume, so a recreated
# container has none even though the database is fully populated. Keep a copy
# on the data volume: reinstalling instead would fail on "already installed".
CONFIG_BACKUP=/var/moodledata/.config.php

if [ ! -f "${CONFIG}" ] && [ -f "${CONFIG_BACKUP}" ]; then
    echo "[entrypoint] restoring config.php from the data volume"
    cp "${CONFIG_BACKUP}" "${CONFIG}"
    chown www-data:www-data "${CONFIG}"
fi

if [ ! -f "${CONFIG}" ]; then
    if php -r '
        $c = @pg_connect(sprintf(
            "host=%s port=5432 dbname=%s user=%s password=%s",
            getenv("POSTGRES_HOST"), getenv("POSTGRES_DB"),
            getenv("POSTGRES_USER"), getenv("POSTGRES_PASSWORD")
        ));
        $r = $c ? @pg_query($c, "SELECT 1 FROM mdl_config LIMIT 1") : false;
        exit($r ? 0 : 1);
    ' 2>/dev/null; then
        echo "[entrypoint] database already installed - writing config.php only"
        SKIP_DATABASE="--skip-database"
    else
        echo "[entrypoint] no config.php - running the unattended install"
        SKIP_DATABASE=""
    fi

    su -s /bin/sh -c "php ${MOODLE_ROOT}/admin/cli/install.php \
        --non-interactive --agree-license ${SKIP_DATABASE} \
        --wwwroot='${MOODLE_WWWROOT}' \
        --dataroot=/var/moodledata \
        --dbtype=pgsql --dbhost='${POSTGRES_HOST}' \
        --dbname='${POSTGRES_DB}' --dbuser='${POSTGRES_USER}' \
        --dbpass='${POSTGRES_PASSWORD}' \
        --fullname='Personalised E-Learning' --shortname='PEL' \
        --adminuser='${MOODLE_ADMIN_USER}' --adminpass='${DEMO_PASSWORD}' \
        --adminemail='${MOODLE_ADMIN_EMAIL}'" www-data
fi

cp "${CONFIG}" "${CONFIG_BACKUP}"
chown www-data:www-data "${CONFIG_BACKUP}"
chmod 600 "${CONFIG_BACKUP}"
echo "[entrypoint] config.php ready"

# Set debug through cfg.php, never by editing config.php: doing that once
# truncated the file and left the container restarting forever. Both this and
# the purge below must stay non-fatal - a site that will not start is worse
# than one without debug settings.
DEBUG_DISPLAY="${MOODLE_DEBUG:-0}"
set +e
su -s /bin/sh -c "php ${MOODLE_ROOT}/admin/cli/cfg.php --name=debug --set=32767" www-data > /dev/null \
    && su -s /bin/sh -c "php ${MOODLE_ROOT}/admin/cli/cfg.php --name=debugdisplay --set=${DEBUG_DISPLAY}" www-data > /dev/null \
    && echo "[entrypoint] debugdisplay=${DEBUG_DISPLAY}" \
    || echo "[entrypoint] WARNING: could not set debug options; continuing"

su -s /bin/sh -c "php ${MOODLE_ROOT}/admin/cli/purge_caches.php" www-data \
    || echo "[entrypoint] WARNING: cache purge failed; continuing"
set -e

echo "[entrypoint] starting apache"
exec apache2-foreground
