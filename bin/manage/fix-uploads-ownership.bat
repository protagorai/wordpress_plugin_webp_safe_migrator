@echo off
REM ==============================================================================
REM WebP Safe Migrator - Upload Ownership Fix Utility
REM Final fallback to fix upload permissions when other methods fail
REM ==============================================================================

echo.
echo =====================================
echo   Upload Ownership Fix Utility
echo =====================================
echo.

REM Check if Podman is available
echo Checking Podman availability...
podman --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Podman not found. Please install Podman first.
    echo.
    echo Installation guides:
    echo   Windows: https://podman.io/getting-started/installation#windows
    echo   Linux: https://podman.io/getting-started/installation#linux
    echo   macOS: https://podman.io/getting-started/installation#macos
    echo.
    pause
    exit /b 1
)
echo * Podman is available

REM Check if WordPress container is running
echo Checking if WordPress container is running...
podman ps --format "{{.Names}}" | findstr webp-migrator-wordpress >nul
if errorlevel 1 (
    echo ERROR: WordPress container not running
    echo Please run 'webp-migrator-simple.bat' first to start containers
    pause
    exit /b 1
)
echo * WordPress container is running

echo.
echo Fixing WordPress upload permissions...
echo.

REM Show current ownership
echo Current ownership status:
podman exec webp-migrator-wordpress bash -c "echo 'wp-content/: ' && stat -c '%U:%G' /var/www/html/wp-content/"
podman exec webp-migrator-wordpress bash -c "echo 'uploads/: ' && stat -c '%U:%G' /var/www/html/wp-content/uploads/ 2>/dev/null || echo 'uploads/: directory not found'"
podman exec webp-migrator-wordpress bash -c "echo 'uploads/2025/: ' && stat -c '%U:%G' /var/www/html/wp-content/uploads/2025/ 2>/dev/null || echo 'uploads/2025/: not created yet'"

echo.
echo Applying ownership fix...

REM Comprehensive ownership fix with detailed logging
podman exec webp-migrator-wordpress bash -c "
echo '[MANUAL-OWNERSHIP-FIX] Starting comprehensive ownership repair...'

# Show before state  
echo '[MANUAL-OWNERSHIP-FIX] BEFORE - wp-content owned by:' \$(stat -c '%U:%G' /var/www/html/wp-content/ 2>/dev/null)
echo '[MANUAL-OWNERSHIP-FIX] BEFORE - uploads owned by:' \$(stat -c '%U:%G' /var/www/html/wp-content/uploads/ 2>/dev/null || echo 'not found')

# Fix ALL wp-content and nested directories
chown -R www-data:www-data /var/www/html/wp-content/
find /var/www/html/wp-content -type d -exec chmod 755 {} \;
find /var/www/html/wp-content -type f -exec chmod 644 {} \;

# Show after state
echo '[MANUAL-OWNERSHIP-FIX] AFTER - wp-content owned by:' \$(stat -c '%U:%G' /var/www/html/wp-content/)
echo '[MANUAL-OWNERSHIP-FIX] AFTER - uploads owned by:' \$(stat -c '%U:%G' /var/www/html/wp-content/uploads/)
echo '[MANUAL-OWNERSHIP-FIX] AFTER - uploads/2025 owned by:' \$(stat -c '%U:%G' /var/www/html/wp-content/uploads/2025/ 2>/dev/null || echo 'not created yet')

echo '[MANUAL-OWNERSHIP-FIX] Manual ownership fix complete!'
"

echo.
echo Comprehensive ownership fix applied:
podman exec webp-migrator-wordpress bash -c "echo 'wp-content/: ' && stat -c '%U:%G' /var/www/html/wp-content/"
podman exec webp-migrator-wordpress bash -c "echo 'uploads/: ' && stat -c '%U:%G' /var/www/html/wp-content/uploads/"
podman exec webp-migrator-wordpress bash -c "echo 'uploads/2025/: ' && stat -c '%U:%G' /var/www/html/wp-content/uploads/2025/ 2>/dev/null || echo 'uploads/2025/: not created yet'"

echo.
echo =====================================
echo   Upload Ownership Fix Complete!
echo =====================================
echo.
echo * wp-content/ and all subdirectories now owned by www-data
echo * File uploads should work correctly now
echo * Try uploading images through WordPress admin
echo.
echo If upload issues persist:
echo   1. Run this script again: fix-uploads-ownership.bat
echo   2. Check container logs: podman logs webp-migrator-wordpress
echo   3. Restart containers: webp-migrator-simple.bat clean / webp-migrator-simple.bat
echo.
pause
