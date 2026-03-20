#!/bin/sh
# Healthcheck for Laravel FPM container
SCRIPT_FILENAME=/var/www/html/public/index.php
DOCUMENT_ROOT=/var/www/html/public

# Test PHP-FPM is responding via cgi-fcgi or direct check
if command -v php-fpm > /dev/null 2>&1; then
    php-fpm -t 2>&1 | grep -q "test is successful" && exit 0
fi

# Fallback: check process is running
pgrep php-fpm > /dev/null 2>&1 && exit 0
exit 1
