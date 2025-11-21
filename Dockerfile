FROM php:8.1-apache

# Enable required PHP extensions
RUN docker-php-ext-install curl

# Copy application files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 755 /var/www/html

# Enable Apache modules
RUN a2enmod rewrite ssl

EXPOSE 80