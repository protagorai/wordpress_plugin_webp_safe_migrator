@echo off
setlocal enabledelayedexpansion
REM ==============================================================================
REM WebP Safe Migrator - Fixed Main Script (Based on Working Simple Version)
REM All the features, but using the reliable patterns from simple script
REM ==============================================================================

if "%1"=="" goto show_help

REM Main commands
if /i "%1"=="start" goto start
if /i "%1"=="stop" goto stop  
if /i "%1"=="restart" goto restart
if /i "%1"=="status" goto status
if /i "%1"=="clean" goto clean
if /i "%1"=="download" goto download
if /i "%1"=="manage" goto manage
if /i "%1"=="help" goto show_help

echo ❌ Unknown command: %1
goto show_help

:show_help
echo.
echo =====================================
echo    WebP Safe Migrator v1.0
echo =====================================
echo.

REM Check system requirements
podman --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Podman not found. This tool requires Podman.
    echo.
    echo Install Podman from: https://podman.io/getting-started/installation
    echo Alternative: Use Docker (replace 'podman' with 'docker' in scripts)
    echo.
    pause
    exit /b 1
)
echo * Podman available
echo 🚀 WebP Safe Migrator - WordPress Plugin Development Environment
echo.
echo COMMANDS:
echo   start       Start the development environment
echo   stop        Stop all containers (keep data)
echo   restart     Stop and start the environment  
echo   clean       Complete cleanup (removes all data)
echo   status      Show current container status
echo   download    Pre-download resources for faster setup
echo   manage      WordPress management utilities
echo   fix         Fix upload permissions (if uploads fail)
echo   help        Show this help message
echo.
echo EXAMPLES:
echo   webp-migrator start         # Start everything
echo   webp-migrator stop          # Stop containers
echo   webp-migrator clean         # Clean slate
echo   webp-migrator download      # Pre-download for speed
echo   webp-migrator fix           # Fix upload permissions
echo.
goto end

:start
echo.
echo =====================================
echo    WebP Safe Migrator Launcher  
echo =====================================
echo.

REM Load configuration
call bin\config\load-config.bat

echo Starting WebP Safe Migrator deployment...
echo.

REM Clean start (like simple script)
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

REM Start database (using reliable simple script approach)
echo Starting database...
echo * Database: %DB_NAME%
echo * User: %DB_WP_USER%
echo * Port: %DB_PORT%
podman run -d --name webp-migrator-mysql --network webp-migrator-net ^
    -p %DB_PORT%:3306 ^
    -e MYSQL_ROOT_PASSWORD=%DB_ROOT_PASS% ^
    -e MYSQL_DATABASE=%DB_NAME% ^
    -e MYSQL_USER=%DB_WP_USER% ^
    -e MYSQL_PASSWORD=%DB_WP_PASS% ^
    mysql:8.0 --default-authentication-plugin=mysql_native_password >nul

if errorlevel 1 (
    echo ERROR: Failed to start MySQL
    pause
    exit /b 1
)

REM Wait for database (simplified timing)
echo Waiting for database (30 seconds)...
timeout /t 30 /nobreak >nul

REM Test database
echo Testing database connection...
podman exec webp-migrator-mysql mysqladmin ping -u root -p%DB_ROOT_PASS% 2>nul >nul
if errorlevel 1 (
    echo ERROR: Database not ready. Check Podman logs.
    pause
    exit /b 1
)
echo * Database is ready!

REM Start WordPress (reliable approach)
echo.
echo Starting WordPress...
echo * Port: %WP_PORT%
echo * Site: %WP_SITE_URL%
podman run -d --name webp-migrator-wordpress --network webp-migrator-net ^
    -p %WP_PORT%:80 ^
    -e WORDPRESS_DB_HOST=webp-migrator-mysql ^
    -e WORDPRESS_DB_USER=%DB_WP_USER% ^
    -e WORDPRESS_DB_PASSWORD=%DB_WP_PASS% ^
    -e WORDPRESS_DB_NAME=%DB_NAME% ^
    -e WORDPRESS_DEBUG=1 ^
    -v "%CD%\src:/var/www/html/wp-content/plugins/webp-safe-migrator" ^
    wordpress:latest >nul

if errorlevel 1 (
    echo ERROR: Failed to start WordPress
    pause
    exit /b 1
)

REM Start phpMyAdmin
echo Starting phpMyAdmin...
echo * Port: %PMA_PORT%
podman run -d --name webp-migrator-phpmyadmin --network webp-migrator-net ^
    -p %PMA_PORT%:80 ^
    -e PMA_HOST=webp-migrator-mysql ^
    -e PMA_USER=root ^
    -e PMA_PASSWORD=%DB_ROOT_PASS% ^
    phpmyadmin:latest >nul

REM Wait for WordPress (simplified)
echo.
echo Waiting for WordPress (60 seconds)...
timeout /t 60 /nobreak >nul

REM Configure PHP
echo Configuring PHP upload limits...
podman exec webp-migrator-wordpress bash -c "echo 'upload_max_filesize = 128M' > /usr/local/etc/php/conf.d/webp-migrator.ini && echo 'post_max_size = 128M' >> /usr/local/etc/php/conf.d/webp-migrator.ini && echo 'max_execution_time = 300' >> /usr/local/etc/php/conf.d/webp-migrator.ini && echo 'memory_limit = 512M' >> /usr/local/etc/php/conf.d/webp-migrator.ini" 2>nul

REM Install WP-CLI and WordPress (reliable approach)
echo Installing WP-CLI...
podman exec webp-migrator-wordpress bash -c "curl -sS https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp && chmod +x /usr/local/bin/wp" 2>nul

echo Installing WordPress and plugin...
podman exec webp-migrator-wordpress wp core install ^
    --url="%WP_SITE_URL%" ^
    --title="%WP_SITE_TITLE%" ^
    --admin_user="%WP_ADMIN_USER%" ^
    --admin_password="%WP_ADMIN_PASS%" ^
    --admin_email="%WP_ADMIN_EMAIL%" ^
    --skip-email --allow-root 2>nul

if errorlevel 1 (
    echo.
    echo WordPress auto-install failed - please complete setup manually:
    echo.
    echo  Go to: %WP_SITE_URL%
    echo  Database: %DB_NAME%
    echo  User: %DB_WP_USER%
    echo  Password: %DB_WP_PASS%
    echo  Host: webp-migrator-mysql
    echo.
    echo Then create admin user: %WP_ADMIN_USER% / %WP_ADMIN_PASS%
    echo.
    pause
) else (
    echo * WordPress installed successfully!
    
    REM CRITICAL: Fix ownership AFTER WordPress installation completes
    echo Fixing WordPress ownership after installation...
    podman exec webp-migrator-wordpress chown -R www-data:www-data /var/www/html/wp-content/ 2>nul
    podman exec webp-migrator-wordpress find /var/www/html/wp-content -type d -exec chmod 755 {} \; 2>nul
    podman exec webp-migrator-wordpress find /var/www/html/wp-content -type f -exec chmod 644 {} \; 2>nul
    echo * WordPress ownership fixed AFTER installation - uploads will work correctly
    
    REM Activate plugin
    echo Activating WebP Safe Migrator plugin...
    podman exec webp-migrator-wordpress wp plugin activate webp-safe-migrator --allow-root 2>nul
    if not errorlevel 1 (
        echo * Plugin activated successfully!
    ) else (
        echo ! Plugin activation failed - activate manually in WordPress admin
    )
)

echo.
echo =====================================
echo     SUCCESS - WebP Migrator Ready!
echo =====================================
echo.
echo  WordPress: %WP_SITE_URL%/wp-admin
echo  Username:  %WP_ADMIN_USER%
echo  Password:  %WP_ADMIN_PASS%
echo.
echo  phpMyAdmin: http://localhost:%PMA_PORT%
echo  Plugin: Media → WebP Migrator
echo.
start %WP_SITE_URL%/wp-admin
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
echo.

echo Commands:
echo   webp-migrator.bat start    # Start/restart
echo   webp-migrator.bat stop     # Stop containers
echo   webp-migrator.bat clean    # Complete cleanup
echo   webp-migrator.bat status   # Show status
echo   webp-migrator.bat download # Pre-download resources
echo   webp-migrator.bat fix      # Fix upload permissions manually
echo.
echo Press any key to close...
pause >nul
goto end

:stop
call bin\manage\stop-webp-migrator.bat
goto end

:restart
call bin\manage\stop-webp-migrator.bat
timeout /t 3 /nobreak >nul
goto start

:clean
call bin\manage\cleanup-webp-migrator.bat
goto end

:status
call bin\manage\status-webp-migrator.bat
goto end

:download
call bin\launch\pre-download-resources.bat
goto end

:manage
call bin\manage\manage-wp.bat %2 %3 %4 %5
goto end

:fix
echo 🛠️ Fixing upload permissions...
call bin\manage\fix-uploads-ownership.bat
goto end

:end
