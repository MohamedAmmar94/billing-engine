#!/bin/bash
# ضبط الملكية والصلاحيات لمجلدات لارفيل
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache
# Start supervisor
service supervisor start

# Start Apache
apache2-foreground
