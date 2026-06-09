# Production container — follows drupal-dsf pattern
# See pipeline/buildspec.yml for build instructions
FROM public.ecr.aws/docker/library/drupal:10-php8.1-apache

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

# Copy application
COPY . /opt/drupal/app
WORKDIR /opt/drupal/app

# Install dependencies
RUN composer install --no-dev --prefer-dist --optimize-autoloader

# Set webroot
ENV APACHE_DOCUMENT_ROOT /opt/drupal/app/web
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
