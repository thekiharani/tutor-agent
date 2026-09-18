# Not 8.5: Moodle 5.2 core raises ~120 deprecation notices on it.
FROM php:8.4-apache

ENV MOODLE_ROOT=/var/www/moodle
ENV MOODLE_DOCROOT=/var/www/moodle/public

# Must stay ahead of the purge below, or curl goes with it and the
# healthcheck fails.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl \
    && rm -rf /var/lib/apt/lists/*

# Only the extensions php:8.4-apache lacks: rebuilding one it already has
# fails with "cp: cannot stat 'modules/*'". The apt-mark/ldd dance below is
# the official PHP images' pattern for keeping the shared libraries the built
# extensions link against while purging the -dev packages, worth ~100MB.
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

COPY moodle/php.ini /usr/local/etc/php/conf.d/zz-moodle.ini

RUN sed -ri "s!/var/www/html!${MOODLE_DOCROOT}!g" \
        /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && a2enmod rewrite headers

# Same tree as download.moodle.org's stable502 tarball, which 403s any
# datacenter IP and so cannot be fetched from CI.
ARG MOODLE_TARBALL=https://github.com/moodle/moodle/archive/refs/heads/MOODLE_502_STABLE.tar.gz
RUN set -eux; \
    mkdir -p "${MOODLE_ROOT}"; \
    curl -fsSL --retry 3 --retry-delay 2 "${MOODLE_TARBALL}" \
        | tar xz --strip-components=1 -C "${MOODLE_ROOT}"; \
    \
    # Of 632 vendored Google service definitions Moodle reaches one, YouTube;
    # the rest are 173MB of dead weight. Do NOT extend this to tests/ -
    # mlbackend/python requires a file under analytics/tests/ and a fresh
    # install dies half-way without it.
    find "${MOODLE_DOCROOT}/lib/google2-service/src" -mindepth 1 -maxdepth 1 \
        ! -name 'YouTube*' -exec rm -rf {} +; \
    \
    mkdir -p /var/moodledata; \
    chown -R www-data:www-data "${MOODLE_ROOT}" /var/moodledata

# The build context is the repository root, not ./moodle, so that this line
# can reach the plugin.
COPY --chown=www-data:www-data plugin/local/tutoragent ${MOODLE_DOCROOT}/local/tutoragent

COPY moodle/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR ${MOODLE_ROOT}
EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
