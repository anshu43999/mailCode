FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libc-client-dev libkrb5-dev ca-certificates \
    && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install imap \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache-site.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/youjian-entrypoint
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html/config \
    && find /var/www/html -type f -name "*.php" -exec chmod 0644 {} \; \
    && find /var/www/html -type f -name "*.html" -exec chmod 0644 {} \; \
    && chmod 0664 /var/www/html/config/access_accounts.json || true \
    && chmod +x /usr/local/bin/youjian-entrypoint

EXPOSE 80

ENTRYPOINT ["youjian-entrypoint"]
CMD ["apache2-foreground"]
