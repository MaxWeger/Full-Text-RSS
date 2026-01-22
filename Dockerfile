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

# 6. FORCE the FT.com config
#    CHANGE 1: User-Agent is now Googlebot to force "Core" (No-JS) HTML.
#    CHANGE 2: Updated 'body' selectors to match the Core layout.
RUN echo "http_header(User-Agent): Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)\n\
http_header(Accept-Language): en-US,en;q=0.9\n\
http_header(Referer): https://www.google.com/\n\
http_header(Cookie): FTSession_s=04eIIS18r0s706cObJFMpe_F0wAAAZvfQTtfw8I.MEUCIQDltpEEs7UvJj-QRr0b-Vxoar_fwM4Fvc2tNxyc6hXefQIgCcq8V3iJvxvn-Xzri3QqSxoeTzWSPo4yqjSUmTetzE0; FTClientSessionId=c5e867e4-2020-408e-93c9-b58d871d3f42;\n\
\n\
normalize_url: yes\n\
\n\
# Extraction Rules for FT Core/No-JS\n\
body: //div[contains(@class, 'article-body')]\n\
body: //div[contains(@class, 'article__content-body')]\n\
body: //article\n\
# Fallback: Capture the main content area if specific tags fail\n\
body: //main\n\
\n\
# Cleanup\n\
strip: //div[contains(@class,'barrier')]\n\
strip: //div[contains(@data-component,'offer-card')]\n\
strip: //aside\n\
strip: //footer\n\
strip: //nav\n\
strip: //div[contains(@class,'ad')]\n\
strip: //div[contains(@class,'o-ads')]\n\
\n\
prune: yes\n\
tidy: yes" > /var/www/html/site_config/custom/ft.com.txt

# 7. Duplicate the config to the 'standard' folder
RUN cp /var/www/html/site_config/custom/ft.com.txt /var/www/html/site_config/standard/ft.com.txt

# 8. Permissions
RUN chown -R www-data:www-data /var/www/html
