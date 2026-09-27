FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql \
    && php -m | grep -E '^(pdo_pgsql|pgsql)$' \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && printf '%s\n' \
       '<Directory /var/www/html>' \
       '    AllowOverride All' \
       '    Require all granted' \
       '</Directory>' \
       '<Directory /var/www/html/public>' \
       '    AllowOverride All' \
       '    Require all granted' \
       '</Directory>' \
       'DirectoryIndex public/index.html index.html' \
       > /etc/apache2/conf-available/ha88.conf \
    && a2enconf ha88

ENV APACHE_DOCUMENT_ROOT=/var/www/html

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD php -r '$u="http://127.0.0.1/api/index.php?action=health"; $c=@file_get_contents($u); exit($c===false?1:0);'
