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

COPY ./proxy/composer.json ./proxy/composer.lock* /var/www/html/
RUN cd /var/www/html && composer install --no-dev --no-interaction --prefer-dist

COPY ./proxy/ /var/www/html/
COPY settings/php.ini /usr/local/etc/php/php.ini

# Install required Apache modules
RUN a2enmod rewrite proxy proxy_http proxy_balancer proxy_connect remoteip

# Copy the Apache configuration files for proxying
COPY files/apache2 /etc/apache2

# Enable Apache config after it is copied in
RUN a2enconf remoteip
