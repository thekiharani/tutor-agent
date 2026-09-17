# Moodle 5.2 needs PHP >= 8.3 and sets no upper bound, but on 8.5 its core
# raises ~120 deprecation notices, which render in the browser when debugging
# is on. 8.4 raises none. Re-test 8.5 when a Moodle release clears them.
FROM php:8.4-apache

# Moodle 5.2 serves from <root>/public; the code root holds config.php and the
# CLI shims. Serving the code root throws a deliberate "rootdirpublic".
ENV MOODLE_ROOT=/var/www/moodle
ENV MOODLE_DOCROOT=/var/www/moodle/public

# Installed before the purge below so they survive it: the entrypoint and the
# healthcheck both need curl.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl \
    && rm -rf /var/lib/apt/lists/*

# Only the extensions from environment.xml that php:8.4-apache does not already
# ship - rebuilding one it has fails with "cp: cannot stat 'modules/*'".
#
# The -dev packages are compile-time only. The apt-mark dance below is the
# official PHP images' pattern for dropping them again: mark everything
# automatic, then re-mark only the shared libraries ldd says the new extensions
# link against, and purge the rest. Worth about 100MB.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev libicu-dev libxml2-dev \
        libzip-dev libpq-dev libsodium-dev libonig-dev; \
    docker-php-ext-configure gd --with-jpeg --with-freetype; \
    docker-php-ext-install -j"$(nproc)" gd intl zip pgsql pdo_pgsql soap exif; \
    apt-mark auto '.*' > /dev/null; \
    [ -z "$savedAptMark" ] || apt-mark manual $savedAptMark > /dev/null; \
    ldd "$(php -r 'echo ini_get("extension_dir");')"/*.so \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); printf "*/%s\n", so }' \
        | sort -u \
        | xargs -r dpkg-query --search 2>/dev/null \
        | cut -d: -f1 \
        | sort -u \
        | xargs -r apt-mark manual > /dev/null; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/conf.d/zz-moodle.ini

# Point Apache at public/, not at the code root.
RUN sed -ri "s!/var/www/html!${MOODLE_DOCROOT}!g" \
        /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && a2enmod rewrite headers

ARG MOODLE_TARBALL=https://download.moodle.org/download.php/direct/stable502/moodle-latest-502.tgz
RUN set -eux; \
    mkdir -p "${MOODLE_ROOT}"; \
    curl -fsSL "${MOODLE_TARBALL}" | tar xz --strip-components=1 -C "${MOODLE_ROOT}"; \
    \
    # Moodle vendors 632 Google API service definitions and references exactly
    # one, YouTube, from repository/youtube. The other 631 are 173MB no code
    # path can reach. The client itself (lib/google2) is untouched.
    find "${MOODLE_DOCROOT}/lib/google2-service/src" -mindepth 1 -maxdepth 1 \
        ! -name 'YouTube*' -exec rm -rf {} +; \
    \
    # Do NOT delete tests/ to save the further 60MB:
    # lib/mlbackend/python/classes/processor.php require_once's a file under
    # analytics/tests/, so a fresh install dies in
    # admin_apply_default_settings() and restarts half-installed.
    \
    mkdir -p /var/moodledata; \
    chown -R www-data:www-data "${MOODLE_ROOT}" /var/moodledata

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR ${MOODLE_ROOT}
EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
