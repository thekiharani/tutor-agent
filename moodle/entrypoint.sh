#!/bin/sh
# Installs Moodle on first boot, then hands over to Apache.  Safe to re-run:
# everything here is guarded, so a container restart does not reinstall.
set -e

MOODLE_ROOT="${MOODLE_ROOT:-/var/www/moodle}"
CONFIG="${MOODLE_ROOT}/config.php"

# The named volume starts empty and root-owned; Moodle writes here as www-data.
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
    # Run as www-data, not root: installing as root leaves moodledata owned by
    # the wrong user and every later CLI script fails in confusing ways.
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

# Developer debug, added once.  These are how the observer output bug in WP3
# becomes visible.  Turn debugdisplay off before the final demo run.
if ! grep -q 'tutoragent-debug' "${CONFIG}"; then
    echo "[entrypoint] enabling developer debugging"
    # Insert before the final require of setup.php, which must stay last.
    su -s /bin/sh -c "php -r '
        \$f = \"${CONFIG}\";
        \$c = file_get_contents(\$f);
        \$add = \"// tutoragent-debug\n\\\$CFG->debug = E_ALL;\n\\\$CFG->debugdisplay = 1;\n\n\";
        \$needle = \"require_once\";
        \$pos = strrpos(\$c, \$needle);
        file_put_contents(\$f, substr(\$c, 0, \$pos) . \$add . substr(\$c, \$pos));
    '" www-data
fi

su -s /bin/sh -c "php ${MOODLE_ROOT}/admin/cli/purge_caches.php" www-data

echo "[entrypoint] starting apache"
exec apache2-foreground
