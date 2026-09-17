# Moodle 5.2 requires PHP >= 8.3 (public/admin/environment.xml, and
# composer.json: "php": ">=8.3.0") and declares no upper bound.  PHP 8.5 was
# tried first and installs fine, but Moodle 5.2 core emits ~120 PHP 8.5
# deprecation notices (non-canonical casts, `case ...;`, the backtick
# operator, xml_parser_free()).  With debugdisplay on for development those
# render in the browser, so 8.4 is the newest version that is actually usable
# here.  Re-test 8.5 when Moodle ships a release that clears them.
FROM php:8.4-apache

# Moodle 5.2 changed its layout: the code root holds config.php and the CLI
# shims, and the *web* root is <root>/public.  Serving the code root directly
# now throws a deliberate "rootdirpublic" exception.
ENV MOODLE_ROOT=/var/www/moodle
ENV MOODLE_DOCROOT=/var/www/moodle/public

# Packages that must survive the build-dependency purge below: curl is used by
# the entrypoint and by the container healthcheck.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl \
    && rm -rf /var/lib/apt/lists/*

# Extensions Moodle 5.2 lists in environment.xml that are NOT already compiled
# into php:8.4-apache.  That image already ships mbstring, sodium, opcache,
# curl, openssl, ctype, dom, xml, xmlreader, simplexml, iconv, json, hash,
# fileinfo and filter - asking docker-php-ext-install to build those again
# fails with "cp: cannot stat 'modules/*'".
#
# The -dev packages are headers and static libraries needed only to compile.
# This is the pattern the official PHP images use to drop them again
# afterwards: remember what was installed by hand, mark everything automatic,
# then ask ldd which shared libraries the new extensions actually link against
# and mark only those as manual. Everything else is purged. Worth about 100MB.
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
    # Moodle vendors 632 generated Google API service definitions and
    # references exactly one of them: YouTube, from repository/youtube. The
    # other 631 - Cloud Composer, Remote Build Execution, Batch and so on -
    # are 173MB that no Moodle code path can reach. The client itself
    # (lib/google2, 216KB) is untouched.
    find "${MOODLE_DOCROOT}/lib/google2-service/src" -mindepth 1 -maxdepth 1 \
        ! -name 'YouTube*' -exec rm -rf {} +; \
    \
    # Deleting Moodle's tests/ directories looks like free space (60MB) and is
    # not: lib/mlbackend/python/classes/processor.php require_once's
    # analytics/tests/classes/mlbackend_helper_trait.php, so production code
    # depends on a test file. Removing them fails the install during
    # admin_apply_default_settings(), and the container then restarts into a
    # half-installed site. Left alone deliberately.
    \
    mkdir -p /var/moodledata; \
    chown -R www-data:www-data "${MOODLE_ROOT}" /var/moodledata

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR ${MOODLE_ROOT}
EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
