# Production container — follows drupal-dsf pattern, adapted for the
# mandala-navina monorepo (the Drupal app lives under drupal/, not the repo root).
# See pipeline/buildspec.yml for build instructions
FROM public.ecr.aws/docker/library/drupal:11-php8.3-apache

# Install additional PHP extensions and utilities
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

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

# Set webroot. config_sync_directory ('../config/sync' in settings.php) resolves
# from here to /opt/drupal/app/drupal/config/sync — the committed CMI baseline.
ENV APACHE_DOCUMENT_ROOT /opt/drupal/app/drupal/web
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
