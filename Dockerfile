# syntax=docker/dockerfile:1
FROM php:8.2-apache

# 1. Install dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    pkg-config libonig-dev libxml2-dev libcurl4-openssl-dev libtidy-dev git ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# 2. Enable Apache modules and PHP extensions
RUN a2enmod rewrite
RUN docker-php-ext-install -j$(nproc) mbstring curl dom tidy xml

# 3. Configure Apache ports
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
 && sed -i 's/:80/:8080/g' /etc/apache2/sites-available/000-default.conf
RUN sed -ri 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html#g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's#AllowOverride None#AllowOverride All#g' /etc/apache2/apache2.conf

# 4. Set working directory and copy app
WORKDIR /var/www/html
COPY . /var/www/html

# 5. Create config directories
RUN mkdir -p /var/www/html/site_config/custom \
 && mkdir -p /var/www/html/site_config/standard

# 6. COPY CONFIGS
# We copy to multiple names to ensure the software finds it
COPY ft.com.txt /var/www/html/site_config/custom/ft.com.txt
RUN cp /var/www/html/site_config/custom/ft.com.txt /var/www/html/site_config/custom/www.ft.com.txt

# 7. NUCLEAR CACHE CLEAR
# We delete the cache folder so the app is forced to fetch fresh data
RUN rm -rf /var/www/html/cache/*

# 8. Permissions
RUN chown -R www-data:www-data /var/www/html
