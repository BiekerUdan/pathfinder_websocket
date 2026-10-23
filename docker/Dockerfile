# Pinned to 8.4.27 (2026-10-21 security release fixing CVE-2026-109618 +
# CVE-2026-109619 unauthenticated RCE). Do NOT float this tag: rebuilds must
# provably contain the patched PHP. Bump deliberately on the next PHP
# security release (Dependabot docker watches).
FROM php:8.5.11-cli-alpine

# Install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy custom PHP configuration
COPY docker/php.ini /usr/local/etc/php/conf.d/websocket.ini

# Set working directory
WORKDIR /app

# Copy composer files first for better layer caching
COPY composer.json composer.lock ./

# Install dependencies (production only)
RUN composer install --no-dev --no-scripts --no-interaction --optimize-autoloader

# Copy application files
COPY app/ ./app/
COPY cmd.php ./cmd.php
COPY docker/suppress_vendor_warnings.php ./docker/suppress_vendor_warnings.php

# Expose WebSocket and TCP ports
# 8020 - WebSocket port for browser connections
# 5555 - TCP port for internal server communication
EXPOSE 8020 5555

# Run the websocket server with default configuration
# tcpHost set to 0.0.0.0 to allow connections from outside the container
# Vendor deprecation warnings are suppressed via error handler in cmd.php
CMD ["php", "cmd.php", "--tcpHost=0.0.0.0"]
