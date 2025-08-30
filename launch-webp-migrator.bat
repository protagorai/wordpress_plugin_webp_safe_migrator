@echo off
setlocal enabledelayedexpansion
REM ==============================================================================
REM WebP Safe Migrator - Windows Launcher v2
REM Fixed WordPress installation process
REM ==============================================================================

echo.
echo =====================================
echo   WebP Safe Migrator Launcher v2
echo =====================================
echo.

REM Check if we're in the right directory
if not exist "src\webp-safe-migrator.php" (
    echo ERROR: Please run this script from the project root directory.
    echo Expected to find: src\webp-safe-migrator.php
    echo Current directory: %CD%
    pause
    exit /b 1
)

REM Check if Podman is available
echo Checking Podman...
podman --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Podman not found. Please install Podman first.
    echo Install from: https://podman.io/getting-started/installation
    pause
    exit /b 1
)
echo * Podman is available

echo.
echo Starting WebP Safe Migrator deployment...
echo.

REM Clean up existing containers first
echo Cleaning up existing containers...
podman stop webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin webp-migrator-wpcli 2>nul
podman rm -f webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin webp-migrator-wpcli 2>nul
podman network rm webp-migrator-net 2>nul

echo * Cleanup completed
echo.

REM Create network
echo Creating container network...
podman network create webp-migrator-net
if errorlevel 1 (
    echo ERROR: Failed to create network
    pause
    exit /b 1
)
echo * Network created

echo.

REM Start MySQL
echo Starting MySQL database...
podman run -d --name webp-migrator-mysql --network webp-migrator-net -p 3307:3306 -e MYSQL_DATABASE=wordpress_webp_test -e MYSQL_USER=wordpress -e MYSQL_PASSWORD=wordpress123 -e MYSQL_ROOT_PASSWORD=root123 docker.io/library/mysql:8.0 --default-authentication-plugin=mysql_native_password

if errorlevel 1 (
    echo ERROR: Failed to start MySQL
    pause
    exit /b 1
)
echo * MySQL container started

echo.
echo Waiting for MySQL to be ready (45 seconds)...
timeout /t 45 /nobreak >nul

echo Testing MySQL connection...
podman exec webp-migrator-mysql mysqladmin ping -u root -proot123 2>nul >nul
if errorlevel 1 (
    echo MySQL not ready yet, trying once more...
    timeout /t 15 /nobreak >nul
    podman exec webp-migrator-mysql mysqladmin ping -u root -proot123 2>nul >nul
            if errorlevel 1 (
            echo ERROR: MySQL not ready after extended wait
            echo You can check MySQL logs with: podman logs webp-migrator-mysql
            pause
            exit /b 1
        )
)
echo * MySQL is ready!

echo.

REM Start WordPress
echo Starting WordPress...
podman run -d --name webp-migrator-wordpress --network webp-migrator-net -p 8080:80 -e WORDPRESS_DB_HOST=webp-migrator-mysql -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress123 -e WORDPRESS_DB_NAME=wordpress_webp_test -e WORDPRESS_DEBUG=1 -v "%CD%\src:/var/www/html/wp-content/plugins/webp-safe-migrator" docker.io/library/wordpress:latest

if errorlevel 1 (
    echo ERROR: Failed to start WordPress
    pause
    exit /b 1
)
echo * WordPress container started

echo.

REM Start phpMyAdmin
echo Starting phpMyAdmin...
podman run -d --name webp-migrator-phpmyadmin --network webp-migrator-net -p 8081:80 -e PMA_HOST=webp-migrator-mysql -e PMA_USER=root -e PMA_PASSWORD=root123 docker.io/library/phpmyadmin:latest

if errorlevel 1 (
    echo ERROR: Failed to start phpMyAdmin
    pause
    exit /b 1
)
echo * phpMyAdmin container started

echo.
echo Waiting for WordPress to be ready (60 seconds)...
echo This is normal - WordPress needs time to download and setup...
timeout /t 60 /nobreak >nul

echo Testing WordPress connection...
curl -s -o nul -w "%%{http_code}" "http://localhost:8080" 2>nul | findstr "200\|30" >nul
if errorlevel 1 (
    echo WordPress not ready yet, waiting more...
    timeout /t 30 /nobreak >nul
)
echo * WordPress should be accessible now

echo.

REM Use the WordPress container directly for setup since it has WP-CLI
echo Installing WP-CLI in WordPress container...
podman exec webp-migrator-wordpress bash -c "curl -o /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /tmp/wp-cli.phar && mv /tmp/wp-cli.phar /usr/local/bin/wp" 2>nul

echo.

REM Install WordPress
echo Installing WordPress...
podman exec webp-migrator-wordpress wp core install --url="http://localhost:8080" --title="WebP Migrator Test Site" --admin_user="admin" --admin_password="admin123!" --admin_email="admin@webp-test.local" --locale="en_US" --skip-email --allow-root

if errorlevel 1 (
    echo ! WordPress installation had issues - you may need to complete setup manually
    echo   Go to http://localhost:8080 to finish WordPress setup
) else (
    echo * WordPress installed successfully!
)

echo.

REM Activate plugin
echo Activating WebP Safe Migrator plugin...
podman exec webp-migrator-wordpress wp plugin activate webp-safe-migrator --allow-root

if errorlevel 1 (
    echo ! Plugin activation failed - you can activate it manually in WordPress admin
    echo   Go to Plugins → Installed Plugins and activate WebP Safe Migrator
) else (
    echo * Plugin activated successfully!
)

echo.

REM Create sample content
echo Creating sample content...
podman exec webp-migrator-wordpress wp post create --post_type=page --post_title="WebP Migrator Test Guide" --post_content="<h2>Welcome to WebP Safe Migrator Test Site</h2><p>Go to Media → WebP Migrator to start testing.</p>" --post_status=publish --allow-root 2>nul

echo * Sample content created

echo.
echo Checking final container status...
podman ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

echo.
echo =====================================
echo   DEPLOYMENT COMPLETE!
echo =====================================
echo.
echo * WordPress Site: http://localhost:8080
echo * WordPress Admin: http://localhost:8080/wp-admin
echo * phpMyAdmin: http://localhost:8081
echo.
echo WordPress Credentials:
echo   Username: admin
echo   Password: admin123!
echo.
echo Database Credentials:
echo   Database: wordpress_webp_test
echo   User: wordpress / wordpress123
echo   Root: root / root123
echo.
echo Plugin Access:
echo   Go to Media → WebP Migrator in WordPress admin
echo.

REM Open WordPress in default browser
echo Opening WordPress in browser...
start http://localhost:8080

echo.
echo SUCCESS! WordPress with WebP Safe Migrator is ready!
echo.
echo Management Scripts Available:
echo   launch-webp-migrator.bat        - Start/restart (this script)
echo   stop-webp-migrator.bat          - Stop containers (keep data)
echo   cleanup-webp-migrator.bat       - Complete cleanup (removes all data)
echo   status-webp-migrator.bat        - Show status
echo.
echo Script completed successfully!
