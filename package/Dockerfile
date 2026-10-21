# Production container — follows drupal-dsf pattern, adapted for the
# mandala-navina monorepo (the Drupal app lives under drupal/, not the repo root).
# See pipeline/buildspec.yml for build instructions
FROM public.ecr.aws/docker/library/drupal:11-php8.3-apache

# Install additional PHP extensions and utilities
#
# default-mysql-client: drush's sql:* commands (sql:dump, sql:cli, ...) wrap
# the system mysqldump/mysql binaries. Without a client present, drush's
# validate hook logs a warning and returns false but drush still EXITS 0 — a
# redirected `drush sql:dump` silently produces an empty file, discoverable
# only at restore time (hit live 2026-08-25, see
# docs/deferred/drupal-image-missing-mysql-client.md). Client tooling only;
# does not touch ADR 012's DB-engine collation concern.
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libfreetype6-dev \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# bcmath is required by simplesamlphp/xml-common (pulled in by simplesamlphp_auth);
# it is not in the base image, and without it `composer install` below fails to
# resolve. Not needed before SAML was added.
RUN docker-php-ext-install bcmath

# phpredis. mandala_solr_visibility's VisibilityTokenStore does `new \Redis()` to
# write the ADR 014 visibility tokens, and it only catches \RedisException — a
# missing extension raises \Error ("Class \"Redis\" not found"), which that catch
# does NOT intercept, so hook_user_login fatals on every login. This works in DDEV
# only because the ddev-redis add-on supplies the extension, which is why it was
# never caught. Also a prerequisite for the planned Drupal object cache.
#
# Built from the pinned GitHub release, not `pecl install redis`: pecl.php.net is a
# single small service, and on 2026-10-21 (the day of a critical PHP security
# release) it answered 500 or took seconds per request, which failed a deploy build
# (execution 9d9cf342). GitHub's release tarball is fast, can be checksummed, and
# the version is explicit. Same extension, same default build options as the pecl
# install (no igbinary, msgpack or compression), so `php --ri redis` is unchanged.
# To bump: change the version, download the tarball and put its `shasum -a 256` in.
ARG PHPREDIS_VERSION=6.3.0
ARG PHPREDIS_SHA256=cb8f81df1a275599e4f8ddcfec7e1f65ed1953e6f5673649149fd680ebff4cad
RUN set -eux; \
    curl -fsSL -o /tmp/phpredis.tar.gz "https://github.com/phpredis/phpredis/archive/refs/tags/${PHPREDIS_VERSION}.tar.gz"; \
    echo "${PHPREDIS_SHA256}  /tmp/phpredis.tar.gz" | sha256sum -c -; \
    mkdir -p /usr/src/php/ext/redis; \
    tar -xzf /tmp/phpredis.tar.gz -C /usr/src/php/ext/redis --strip-components=1; \
    docker-php-ext-install redis; \
    rm -rf /tmp/phpredis.tar.gz /usr/src/php/ext/redis; \
    php --ri redis | grep -E "Redis Version"

# Enable Apache modules
RUN a2enmod proxy proxy_http headers rewrite

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy application. The monorepo root lands at /opt/drupal/app; the Composer
# project (composer.json) and webroot live under its drupal/ subdirectory.
COPY . /opt/drupal/app
WORKDIR /opt/drupal/app/drupal

# Install dependencies (composer.json is in the drupal/ subdir)
RUN composer install --no-dev --prefer-dist --optimize-autoloader

# Put composer-installed binaries on PATH — drush above all, but also whatever
# else composer.json's "require" pulls in a bin/ for (today just drush/drush's
# `drush` and `drush.php`; keeps working automatically as dependencies change,
# rather than hand-symlinking one name at a time). Without this, `drush` only
# works via the full vendor/bin/drush path (as deploy_backend.yml and the
# migration runbooks already do) — a bare `drush` in an interactive shell
# reports "command not found" even though it's installed and working.
ENV PATH="/opt/drupal/app/drupal/vendor/bin:${PATH}"

# Mount SimpleSAMLphp under the same host as Drupal (ProxyPass /simplesaml/ → sp:80).
# Installed to sites-available, NOT sites-enabled: the stock image ships
# sites-enabled/000-default.conf as a symlink into sites-available, and the sed
# below only rewrites sites-available/*.conf. Writing to sites-enabled would
# replace the symlink with a regular file the sed never sees, stranding
# DocumentRoot at the literal /var/www/html.
COPY package/data/files/etc/apache2/sites-available/000-default.conf /etc/apache2/sites-available/000-default.conf

# Set webroot. config_sync_directory ('../config/sync' in settings.php) resolves
# from here to /opt/drupal/app/drupal/config/sync — the committed CMI baseline.
ENV APACHE_DOCUMENT_ROOT /opt/drupal/app/drupal/web
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
