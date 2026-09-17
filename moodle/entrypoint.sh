#!/bin/sh
# Installs Moodle on first boot, then hands over to Apache. Guarded throughout,
# so a restart does not reinstall.
set -e

MOODLE_ROOT="${MOODLE_ROOT:-/var/www/moodle}"
CONFIG="${MOODLE_ROOT}/config.php"

# The named volume starts root-owned; Moodle writes here as www-data.
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
    # As www-data, not root, or moodledata ends up owned by the wrong user and
    # every later CLI script fails confusingly.
    su -s /bin/sh -c "php ${MOODLE_ROOT}/admin/cli/install.php \
        --non-interactive --agree-license \
        --wwwroot='${MOODLE_WWWROOT}' \
        --dataroot=/var/moodledata \
        --dbtype=pgsql --dbhost='${POSTGRES_HOST}' \
        --dbname='${POSTGRES_DB}' --dbuser='${POSTGRES_USER}' \
        --dbpass='${POSTGRES_PASSWORD}' \
        --fullname='Personalised E-Learning' --shortname='PEL' \
        --adminuser='${MOODLE_ADMIN_USER}' --adminpass='${MOODLE_ADMIN_PASS}' \
        --adminemail='${MOODLE_ADMIN_EMAIL}'" www-data
    echo "[entrypoint] install finished"
fi

# Rewritten every boot so MOODLE_DEBUG in .env takes effect on restart. Off by
# default: a stray notice on screen mid-presentation is worse than a silent one
# in the log. Set it to 1 while editing the plugin.
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
