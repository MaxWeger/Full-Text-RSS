# Dockerfile
FROM php:8.2-apache

# Install build dependencies and libraries required by PHP extensions:
# - libonig-dev for mbstring regex support
# - libxml2-dev for DOM/XML
# - libcurl4-openssl-dev for curl
# - libtidy-dev if the app uses tidy (HTML cleaning)
# - pkg-config and build tools
RUN apt-get update && apt-get install -y --no-install-recommends \
    pkg-config \
    libonig-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libtidy-dev \
    git \
    ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite (FTR may rely on pretty URLs / .htaccess)
RUN a2enmod rewrite

# Build and enable PHP extensions.
# Note: json is bundled in PHP 8 and does not need compiling.
RUN docker-php-ext-install -j$(nproc) mbstring curl dom tidy xml

# Switch Apache to listen on 8080 (to match Fly internal_port)
EXPOSE 8080
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
 && sed -i 's/:80/:8080/g' /etc/apache2/sites-available/000-default.conf

# Optional: tighten default vhost to allow .htaccess overrides if needed
RUN sed -ri 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html#g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's#AllowOverride None#AllowOverride All#g' /etc/apache2/apache2.conf

# Copy application source into the web root
COPY . /var/www/html

# Ensure correct permissions for Apache www-data
RUN chown -R www-data:www-data /var/www/html

# Set working directory
WORKDIR /var/www/html
