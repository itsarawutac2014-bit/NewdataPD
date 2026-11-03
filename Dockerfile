FROM php:8.2-apache

# Enable Apache mod_rewrite & mod_headers
RUN a2enmod rewrite headers \
    && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

# Copy all project files to web root
COPY . /var/www/html/

# Ensure data directory exists and has write permissions
RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html \
    && chmod -R 777 /var/www/html/data

EXPOSE 80

CMD ["apache2-foreground"]
