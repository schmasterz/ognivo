FROM php:8.5-fpm-alpine

# System dependencies (bash, git, curl, zip/unzip, openssl CLI + config, fcgi for ping)
RUN apk add --no-cache \
    bash \
    git \
    curl \
    fcgi \
    zip \
    unzip \
    openssl \
    libpng-dev

# PHP extensions required for Laravel
# mbstring and openssl are built into the official PHP image, so they are not installed here
RUN docker-php-ext-install pdo_mysql exif pcntl bcmath gd

# Verify ext-openssl and ext-mbstring are available (build fails if missing)
RUN php -m | grep -qi '^openssl$' && php -m | grep -qi '^mbstring$'

# Configure PHP-FPM ping endpoint for healthchecks
RUN echo -e "[www]\nping.path = /ping\nping.response = pong\n" > /usr/local/etc/php-fpm.d/docker-ping.conf

# Composer (pinned)
COPY --from=composer:2.8.2 /usr/bin/composer /usr/bin/composer

# Install entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/html

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
