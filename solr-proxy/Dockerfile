FROM php:7.4-apache

# php:7.4-apache is Debian 11 (bullseye), which Debian has ended support for: its
# packages are no longer served from deb.debian.org (the 2026-10-14 build failed with
# 404s there), so apt is pointed at archive.debian.org. The archive's Release files are
# expired, hence Check-Valid-Until=false. This is a stopgap on an end-of-life base image;
# the real fix is a newer PHP/Debian base (needs the proxy code checked on it).
#
# Redis extension (ADR 014: proxy reads Drupal-written visibility tokens
# from Redis at mandala_solr_fq:{uid}) + composer for dependency install.
RUN sed -i -e 's|http://deb.debian.org/debian-security|http://archive.debian.org/debian-security|' \
           -e 's|http://deb.debian.org/debian|http://archive.debian.org/debian|' /etc/apt/sources.list \
    && echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99archive \
    && apt-get update \
    && apt-get install -y --no-install-recommends libssl-dev unzip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove libssl-dev \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# composer.lock is REQUIRED here, not globbed-optional. Without it `composer
# install` silently degrades to a fresh resolve on every build, so two builds of
# the same commit can ship different dependency trees. This fork did exactly
# that: it dropped the D7 lock, and the unpinned `dev-master` constraint then
# resolved to Guzzle 8.x-dev while production runs the Guzzle 7 line. Failing
# loudly on a missing lock is the point — do not restore the `*` glob.
# --no-scripts: nothing here defines any, and it keeps the build from running
# package-supplied code.
COPY ./proxy/composer.json ./proxy/composer.lock /var/www/html/
RUN cd /var/www/html && composer install --no-dev --no-interaction --prefer-dist --no-scripts

COPY ./proxy/ /var/www/html/
COPY settings/php.ini /usr/local/etc/php/php.ini

# Bake the settings files into the image. They read every value from the container
# environment (getenv()) and therefore contain NO secrets and NOTHING
# environment-specific — which is what makes this image deployment-agnostic, the
# same shape drupal-netbadge uses: one image, configured per environment from the
# container_0.env.{generated,managed,secret} layering.
#
# This replaces the deploy-time bind mount of settings/. proxy/*.php require these
# two paths unconditionally, so without them baked in the container fatals on the
# first request — the mount was previously the only thing supplying them.
#
# Local development still overrides these by mounting ./settings over the
# directory (see docker-compose.yml); .dockerignore keeps a developer's real
# settings/creds.php and settings/paths.php out of the build context so only the
# templates are ever baked.
COPY settings/paths.php.template /usr/local/etc/settings/paths.php
COPY settings/creds.php.template /usr/local/etc/settings/creds.php

# Install required Apache modules
RUN a2enmod rewrite proxy proxy_http proxy_balancer proxy_connect remoteip

# Copy the Apache configuration files for proxying
COPY files/apache2 /etc/apache2

# Enable Apache config after it is copied in
RUN a2enconf remoteip
