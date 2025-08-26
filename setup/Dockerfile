# WebP Safe Migrator - Enhanced WordPress Development Container
# Based on official WordPress image with additional optimizations

ARG WORDPRESS_VERSION=latest
FROM wordpress:${WORDPRESS_VERSION}

# Install additional system packages
RUN apt-get update && apt-get install -y \
    # SSL/TLS support
    openssl \
    ca-certificates \
    # Image processing libraries
    libwebp-dev \
    libavif-dev \
    libjpeg-dev \
    libpng-dev \
    libfreetype6-dev \
    # Additional utilities
    wget \
    curl \
    unzip \
    git \
    nano \
    # Certbot for SSL certificates
    certbot \
    python3-certbot-apache \
    # Performance monitoring
    htop \
    # Network utilities
    net-tools \
    iputils-ping \
    && rm -rf /var/lib/apt/lists/*

# Configure PHP extensions for optimal image handling
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp \
    && docker-php-ext-install -j$(nproc) \
    gd \
    exif \
    bcmath \
    opcache

# Install additional PHP extensions
RUN pecl install \
    redis \
    imagick \
    && docker-php-ext-enable \
    redis \
    imagick

# Optimize PHP configuration for WordPress and image processing
RUN { \
    echo 'memory_limit = 512M'; \
    echo 'upload_max_filesize = 100M'; \
    echo 'post_max_size = 100M'; \
    echo 'max_execution_time = 300'; \
    echo 'max_input_time = 300'; \
    echo 'max_input_vars = 3000'; \
    echo 'file_uploads = On'; \
    echo 'allow_url_fopen = On'; \
    echo 'display_errors = On'; \
    echo 'log_errors = On'; \
    echo 'error_log = /var/log/php_errors.log'; \
    echo 'date.timezone = UTC'; \
    echo ''; \
    echo '; OpCache optimization'; \
    echo 'opcache.enable = 1'; \
    echo 'opcache.memory_consumption = 128'; \
    echo 'opcache.interned_strings_buffer = 8'; \
    echo 'opcache.max_accelerated_files = 4000'; \
    echo 'opcache.revalidate_freq = 2'; \
    echo 'opcache.fast_shutdown = 1'; \
    echo ''; \
    echo '; Image processing optimization'; \
    echo 'gd.jpeg_ignore_warning = 1'; \
} > /usr/local/etc/php/conf.d/webp-migrator.ini

# Enable Apache modules for SSL and rewrite
RUN a2enmod ssl rewrite headers expires deflate

# Create SSL certificate directory
RUN mkdir -p /etc/ssl/certs/webp-migrator

# Copy custom Apache configuration
COPY apache-ssl.conf /etc/apache2/sites-available/webp-migrator-ssl.conf

# Create startup script for SSL setup
COPY docker-entrypoint-ssl.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint-ssl.sh

# Create log directories
RUN mkdir -p /var/log/webp-migrator && \
    chown www-data:www-data /var/log/webp-migrator

# Set working directory
WORKDIR /var/www/html

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# Use custom entrypoint for SSL setup
ENTRYPOINT ["/usr/local/bin/docker-entrypoint-ssl.sh"]
CMD ["apache2-foreground"]
