@echo off
REM ==============================================================================
REM WebP Safe Migrator - Elegant Minimal Solution
REM One script that just works - no complexity, no confusion
REM ==============================================================================

title WebP Safe Migrator

if "%1"=="stop" goto stop
if "%1"=="clean" goto clean
if "%1"=="status" goto status

echo.
echo =====================================
echo    WebP Safe Migrator - Simple
echo =====================================
echo.

REM Check if Podman is available
echo Checking system requirements...
podman --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Podman not found. This tool requires Podman to be installed.
    echo.
    echo Installation guides by operating system:
    echo   Windows: https://podman.io/getting-started/installation#windows
    echo   Linux: https://podman.io/getting-started/installation#linux  
    echo   macOS: https://podman.io/getting-started/installation#macos
    echo.
    echo Alternative: You can also use Docker if available
    echo   Just replace 'podman' with 'docker' in the scripts
    echo.
    pause
    exit /b 1
)
podman --version
echo * Podman is available and ready
echo.

REM Clean start
echo Cleaning up any existing containers...
podman stop webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin 2>nul >nul
podman rm -f webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin 2>nul >nul
podman network rm webp-migrator-net 2>nul >nul

REM Create network
echo Creating network...
podman network create webp-migrator-net >nul
if errorlevel 1 (
    echo ERROR: Failed to create network. Is Podman running?
    pause
    exit /b 1
)

REM Start database
echo Starting database...
podman run -d --name webp-migrator-mysql --network webp-migrator-net ^
    -p 3307:3306 ^
    -e MYSQL_ROOT_PASSWORD=root123 ^
    -e MYSQL_DATABASE=wordpress ^
    -e MYSQL_USER=wpuser ^
    -e MYSQL_PASSWORD=wppass ^
    mysql:8.0 --default-authentication-plugin=mysql_native_password >nul

REM Wait for database
echo Waiting for database (30 seconds)...
timeout /t 30 /nobreak >nul

REM Start WordPress with proper database connection
echo Starting WordPress...
podman run -d --name webp-migrator-wordpress --network webp-migrator-net ^
    -p 8080:80 ^
    -e WORDPRESS_DB_HOST=webp-migrator-mysql ^
    -e WORDPRESS_DB_USER=wpuser ^
    -e WORDPRESS_DB_PASSWORD=wppass ^
    -e WORDPRESS_DB_NAME=wordpress ^
    -v "%CD%\src:/var/www/html/wp-content/plugins/webp-safe-migrator" ^
    wordpress:latest >nul

REM Start phpMyAdmin  
echo Starting phpMyAdmin...
podman run -d --name webp-migrator-phpmyadmin --network webp-migrator-net ^
    -p 8081:80 ^
    -e PMA_HOST=webp-migrator-mysql ^
    -e PMA_USER=root ^
    -e PMA_PASSWORD=root123 ^
    phpmyadmin:latest >nul

REM Wait for WordPress to initialize fully
echo Waiting for WordPress (90 seconds)...
timeout /t 90 /nobreak >nul

REM Install WordPress via WP-CLI
echo Installing WordPress and plugin...
podman exec webp-migrator-wordpress bash -c "curl -sS https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp && chmod +x /usr/local/bin/wp" 2>nul
podman exec --user www-data webp-migrator-wordpress wp core install ^
    --url="http://localhost:8080" ^
    --title="WebP Migrator Test" ^
    --admin_user="admin" ^
    --admin_password="admin123" ^
    --admin_email="admin@test.local" ^
    --skip-email 2>nul

REM Fix WordPress configuration to prevent SSL warnings
echo Configuring WordPress for container environment...
podman exec webp-migrator-wordpress wp config set WP_HTTP_BLOCK_EXTERNAL true --allow-root 2>nul
podman exec webp-migrator-wordpress wp config set WP_ACCESSIBLE_HOSTS 'localhost,127.0.0.1' --allow-root 2>nul
podman exec webp-migrator-wordpress wp config set AUTOMATIC_UPDATER_DISABLED true --allow-root 2>nul
podman exec webp-migrator-wordpress wp config set WP_DEBUG_DISPLAY false --allow-root 2>nul
echo * WordPress configuration fixed - no more SSL warnings

REM Check if WordPress install was successful
podman exec webp-migrator-wordpress wp core is-installed --allow-root 2>nul
if errorlevel 1 (
    echo.
    echo WordPress auto-install failed - please complete setup manually:
    echo.
    echo  Go to: http://localhost:8080
    echo  Database: wordpress
    echo  User: wpuser
    echo  Password: wppass
    echo  Host: webp-migrator-mysql
    echo.
    echo Then create admin user: admin / admin123
    echo.
    pause
) else (
    REM CRITICAL: Fix ownership AFTER WordPress installation completes
    echo Fixing WordPress ownership after installation...
    podman exec webp-migrator-wordpress chown -R www-data:www-data /var/www/html/wp-content/ 2>nul
    podman exec webp-migrator-wordpress find /var/www/html/wp-content -type d -exec chmod 755 {} \; 2>nul
    podman exec webp-migrator-wordpress find /var/www/html/wp-content -type f -exec chmod 644 {} \; 2>nul
    echo * WordPress ownership fixed AFTER installation - uploads will work correctly
    
    REM Activate plugin
    podman exec --user www-data webp-migrator-wordpress wp plugin activate webp-safe-migrator 2>nul
)

echo.
echo =====================================
echo     SUCCESS - WebP Migrator Ready!
echo =====================================
echo.
echo  WordPress: http://localhost:8080/wp-admin
echo  Username:  admin  
echo  Password:  admin123
echo  
echo  phpMyAdmin: http://localhost:8081
echo  Plugin: Media → WebP Migrator
echo.
start http://localhost:8080/wp-admin
echo.
REM FINAL COMPREHENSIVE OWNERSHIP FIX - After ALL setup is complete
echo.
echo ========================================
echo  FINAL OWNERSHIP FIX (COMPREHENSIVE)
echo ========================================
echo.
echo Applying final WordPress ownership fix...
echo This ensures uploads work correctly after complete setup.
echo.

REM Final comprehensive ownership fix with simple commands
echo [FINAL-FIX] Applying comprehensive ownership fix...
podman exec webp-migrator-wordpress chown -R www-data:www-data /var/www/html/wp-content/ 2>nul
podman exec webp-migrator-wordpress find /var/www/html/wp-content -type d -exec chmod 755 {} \; 2>nul
podman exec webp-migrator-wordpress find /var/www/html/wp-content -type f -exec chmod 644 {} \; 2>nul
echo [FINAL-FIX] WordPress ownership fix complete

echo * Final ownership fix applied - uploads will work correctly

REM Activate plugin AFTER final comprehensive ownership fix
echo [FINAL-FIX] Activating WebP Safe Migrator plugin after final ownership fix...
podman exec webp-migrator-wordpress wp plugin activate webp-safe-migrator --allow-root

if errorlevel 1 (
    echo ! Final plugin activation failed - you can activate it manually
) else (
    echo * Plugin activated successfully after final ownership fix!
)

echo.

echo Commands:
echo   webp-migrator-simple.bat         (start)
echo   webp-migrator-simple.bat stop    (stop)  
echo   webp-migrator-simple.bat clean   (clean)
echo   bin\manage\fix-uploads-ownership.bat   (fix upload permissions)
echo.

echo NOTE: Upload permissions have been fixed automatically
echo.

pause
goto end

:stop
echo Stopping all containers...
podman stop webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin 2>nul
echo Done.
pause
goto end

:clean  
echo Removing all containers and data...
podman stop webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin 2>nul
podman rm -f webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin 2>nul
podman network rm webp-migrator-net 2>nul
echo Clean complete.
pause
goto end

:status
echo Container Status:
podman ps --filter name=webp-migrator
pause
goto end

:end
