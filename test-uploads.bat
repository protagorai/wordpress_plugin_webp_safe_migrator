@echo off
echo Testing upload ownership after fix...
echo.
echo Current uploads ownership:
podman exec webp-migrator-wordpress ls -la /var/www/html/wp-content/uploads/
echo.
echo Current uploads/2025 ownership:
podman exec webp-migrator-wordpress ls -la /var/www/html/wp-content/uploads/2025/ 2>nul || echo "2025 directory not created yet"
echo.
echo Current uploads/2025/08 ownership:
podman exec webp-migrator-wordpress ls -la /var/www/html/wp-content/uploads/2025/08/ 2>nul || echo "08 directory not created yet"
echo.
pause
