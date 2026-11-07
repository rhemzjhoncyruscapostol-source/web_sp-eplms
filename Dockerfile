ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-apache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install the PHP extensions used by the API.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring \
    && apt-get purge -y --auto-remove libonig-dev \
    && apt-get install -y --no-install-recommends libonig5 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && sed -ri 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#' /etc/apache2/sites-available/000-default.conf

# Copy app files
COPY . /var/www/html/
COPY docker-entrypoint.sh /usr/local/bin/sp-eplms-entrypoint

# Fix permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod +x /usr/local/bin/sp-eplms-entrypoint

EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/sp-eplms-entrypoint"]
CMD ["apache2-foreground"]