#!/bin/sh
set -e

mkdir -p /var/www/html/config

if [ ! -f /var/www/html/config/mail.php ] && [ -f /var/www/html/config/mail.example.php ]; then
    cp /var/www/html/config/mail.example.php /var/www/html/config/mail.php
fi

if [ ! -f /var/www/html/config/access_accounts.json ]; then
    printf '{}\n' > /var/www/html/config/access_accounts.json
fi

if [ ! -f /var/www/html/config/cdk_records.json ]; then
    printf '{}\n' > /var/www/html/config/cdk_records.json
fi

if [ ! -f /var/www/html/config/security_events.json ]; then
    printf '{}\n' > /var/www/html/config/security_events.json
fi

chown -R www-data:www-data /var/www/html/config 2>/dev/null || true
chmod 0664 /var/www/html/config/mail.php 2>/dev/null || true
chmod 0664 /var/www/html/config/access_accounts.json 2>/dev/null || true
chmod 0664 /var/www/html/config/cdk_records.json 2>/dev/null || true
chmod 0664 /var/www/html/config/security_events.json 2>/dev/null || true

exec docker-php-entrypoint "$@"
