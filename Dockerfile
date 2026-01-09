# Venera-Dent Booking System
FROM php:8.2-apache

# Install PostgreSQL extension, GD library, cURL, and cron
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    curl \
    cron \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql gd curl \
    && apt-get clean

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set DocumentRoot to public folder
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Setup cron job for SMS sending (every 5 minutes)
RUN echo '*/5 * * * * cd /var/www/html && php cron_sms.php >> /var/log/sms_cron.log 2>&1' > /etc/cron.d/sms-cron
RUN chmod 0644 /etc/cron.d/sms-cron
RUN crontab /etc/cron.d/sms-cron

# Create a start script that runs both Apache and cron
RUN echo '#!/bin/bash\ncron &\napache2-foreground' > /start.sh
RUN chmod +x /start.sh

# Expose port 80
EXPOSE 80

# Start both Apache and Cron
CMD ["/start.sh"]
