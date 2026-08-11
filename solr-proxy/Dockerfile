FROM php:7.4-apache

# Redis extension (ADR 014: proxy reads Drupal-written visibility tokens
# from Redis at mandala_solr_fq:{uid}) + composer for dependency install.
RUN apt-get update \
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
