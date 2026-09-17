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

if [ ! -f "${CONFIG}" ]; then
    echo "[entrypoint] no config.php - running the unattended install"
    # As www-data, or moodledata ends up root-owned and later CLI scripts fail.
    su -s /bin/sh -c "php ${MOODLE_ROOT}/admin/cli/install.php \
        --non-interactive --agree-license \
        --wwwroot='${MOODLE_WWWROOT}' \
        --dataroot=/var/moodledata \
        --dbtype=pgsql --dbhost='${POSTGRES_HOST}' \
        --dbname='${POSTGRES_DB}' --dbuser='${POSTGRES_USER}' \
        --dbpass='${POSTGRES_PASSWORD}' \
        --fullname='Personalised E-Learning' --shortname='PEL' \
        --adminuser='${MOODLE_ADMIN_USER}' --adminpass='${DEMO_PASSWORD}' \
        --adminemail='${MOODLE_ADMIN_EMAIL}'" www-data
    echo "[entrypoint] install finished"
fi

# Rewritten each boot so MOODLE_DEBUG takes effect on restart.
DEBUG_DISPLAY="${MOODLE_DEBUG:-0}"
sed -i '/tutoragent-debug-start/,/tutoragent-debug-end/d' "${CONFIG}"
LASTREQUIRE=$(grep -n "require_once" "${CONFIG}" | tail -1 | cut -d: -f1)
sed -i "${LASTREQUIRE}i\
// tutoragent-debug-start\
\$CFG->debug = E_ALL;\
\$CFG->debugdisplay = ${DEBUG_DISPLAY};\
// tutoragent-debug-end" "${CONFIG}"
echo "[entrypoint] debugdisplay=${DEBUG_DISPLAY}"

su -s /bin/sh -c "php ${MOODLE_ROOT}/admin/cli/purge_caches.php" www-data

echo "[entrypoint] starting apache"
exec apache2-foreground
