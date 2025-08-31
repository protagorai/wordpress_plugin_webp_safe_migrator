@echo off
REM Quick fix for current upload issues
echo Fixing WordPress uploads ownership...
podman exec webp-migrator-wordpress chown -R www-data:www-data /var/www/html/wp-content/
podman exec webp-migrator-wordpress chmod -R 755 /var/www/html/wp-content/
echo Fixed! Try uploading now.
pause
