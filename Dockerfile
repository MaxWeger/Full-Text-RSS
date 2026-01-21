FROM php:8.2-apache

# Install dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    pkg-config libonig-dev libxml2-dev libcurl4-openssl-dev libtidy-dev git ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite

# Install PHP extensions
RUN docker-php-ext-install -j$(nproc) mbstring curl dom tidy xml

# Configure Apache ports
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
 && sed -i 's/:80/:8080/g' /etc/apache2/sites-available/000-default.conf

# Allow .htaccess overrides
RUN sed -ri 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html#g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's#AllowOverride None#AllowOverride All#g' /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# 1. Copy the application code
COPY . /var/www/html

# 2. Create the custom config folder
RUN mkdir -p /site_config/custom

# 3. Copy your custom config file
COPY ft.com.txt site_config/custom/ft.com.txt

# Permissions
RUN chown -R www-data:www-data /var/www/html
