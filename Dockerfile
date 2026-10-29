FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends default-mysql-client \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html
COPY apache.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod rewrite

EXPOSE 10000

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
