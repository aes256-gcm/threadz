FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libpq-dev postgresql-client \
    && docker-php-ext-install mbstring pdo_pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

COPY . /var/www/html
RUN mkdir -p /var/www/html/public/uploads \
    && chown -R www-data:www-data /var/www/html/public/uploads \
    && chmod +x /var/www/html/docker-entrypoint.sh

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]

