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

# wwwroot and sslproxy are written once by the installer and then restored from
# the backup above, so changing them in .env would otherwise never take effect.
# Rewrite both on every start. The earlier truncation came from editing
# config.php in place: build the new file beside it, refuse it unless php -l
# parses it and wwwroot survived, and only then overwrite through cat, which
# keeps the existing owner and mode.
# Moodle throws a fatal coding_exception if sslproxy is on and wwwroot is not
# https, which serves a white page rather than a wrong one. Refuse the
# combination here instead.
if [ "${MOODLE_SSLPROXY:-0}" = "1" ]; then
    case "${MOODLE_WWWROOT}" in
        https://*) SSLPROXY=true ;;
        *) SSLPROXY=false
           echo "[entrypoint] WARNING: MOODLE_SSLPROXY=1 needs an https MOODLE_WWWROOT; ignoring it" ;;
    esac
else
    SSLPROXY=false
fi
# Moodle nags every unregistered site with "Don't miss out on important updates
# and security alerts" across the admin pages. The renderer shows it when
# !is_registered() && site_is_public(), and site_is_public() returns
# $CFG->site_is_public first if an admin has set it. That flag gates nothing
# else: its only four uses in core are the banner, the registration page, the
# registration prompt and the registration cron task. Setting it false is
# therefore how you turn the nagging off without pretending to be registered.
# MOODLE_REGISTRATION_PROMPT=1 leaves Moodle to decide for itself.
if [ "${MOODLE_REGISTRATION_PROMPT:-0}" = "1" ]; then
    PUBLIC_LINE=""
else
    PUBLIC_LINE="false"
fi
CONFIG_NEW="${CONFIG}.new"
awk -v url="${MOODLE_WWWROOT}" -v ssl="${SSLPROXY}" -v pub="${PUBLIC_LINE}" -v q="'" '
    /^\$CFG->wwwroot/        { next }
    /^\$CFG->sslproxy/       { next }
    /^\$CFG->site_is_public/ { next }
    /^require_once/ {
        print "$CFG->wwwroot   = " q url q ";";
        print "$CFG->sslproxy  = " ssl ";";
        if (pub != "") {
            print "$CFG->site_is_public = " pub ";";
        }
        print "";
    }
    { print }
' "${CONFIG}" > "${CONFIG_NEW}" 2>/dev/null

if php -l "${CONFIG_NEW}" > /dev/null 2>&1 && grep -q "^\$CFG->wwwroot" "${CONFIG_NEW}"; then
    cat "${CONFIG_NEW}" > "${CONFIG}"
    echo "[entrypoint] wwwroot=${MOODLE_WWWROOT} sslproxy=${SSLPROXY}"
    [ -z "${PUBLIC_LINE}" ] || echo "[entrypoint] registration prompt suppressed (site_is_public=false)"
else
    echo "[entrypoint] WARNING: could not rewrite wwwroot/sslproxy; keeping config.php"
fi
rm -f "${CONFIG_NEW}"

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

# Seeding runs on every start, not just the first: the script creates what is
# missing and brings courses and activities into line with seed_demo.php, while
# leaving existing users, passwords and saved learning styles alone. Non-fatal
# for the same reason as everything else here - a site that will not start is
# worse than a site with no demo data. Set SEED_ON_START=0 to skip it.
if [ "${SEED_ON_START:-1}" = "1" ]; then
    echo "[entrypoint] seeding demo courses"
    su -s /bin/sh -c "php ${MOODLE_ROOT}/public/local/tutoragent/cli/seed_demo.php" www-data \
        || echo "[entrypoint] WARNING: seeding failed; continuing"
else
    echo "[entrypoint] SEED_ON_START=0, skipping the demo seed"
fi

su -s /bin/sh -c "php ${MOODLE_ROOT}/admin/cli/purge_caches.php" www-data \
    || echo "[entrypoint] WARNING: cache purge failed; continuing"
set -e

echo "[entrypoint] starting apache"
exec apache2-foreground
