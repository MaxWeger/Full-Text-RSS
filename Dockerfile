# Dockerfile
FROM php:8.2-apache
RUN docker-php-ext-install mbstring json
COPY . /var/www/html
# If app lives in a subfolder, adjust the copy path and DocumentRoot
EXPOSE 8080
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
 && sed -i 's/:80/:8080/g' /etc/apache2/sites-available/000-default.conf
