FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends default-mysql-client \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html
COPY apache.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod rewrite

# Security: every PHP page runs gate.php first (sign-in + role checks),
# visitors never see PHP error messages, and session cookies are locked down.
RUN { \
      echo "auto_prepend_file=/var/www/html/gate.php"; \
      echo "display_errors=Off"; \
      echo "log_errors=On"; \
      echo "expose_php=Off"; \
      echo "session.use_strict_mode=1"; \
      echo "session.use_only_cookies=1"; \
      echo "session.cookie_httponly=1"; \
      echo "session.cookie_samesite=Lax"; \
    } > /usr/local/etc/php/conf.d/zz-medbot.ini

# Do not publish build and server files on the website
RUN rm -rf /var/www/html/Dockerfile /var/www/html/docker-entrypoint.sh /var/www/html/render.yaml /var/www/html/apache.conf /var/www/html/.git /var/www/html/.github

EXPOSE 10000

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
