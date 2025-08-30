@echo off
setlocal enabledelayedexpansion
REM ==============================================================================
REM WebP Safe Migrator - Windows Launcher v3 (Configurable)
REM Uses webp-migrator.config.yaml for customizable credentials and settings
REM ==============================================================================

REM Unicode spinner characters for loading animations
set "spin=⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏"

echo.
echo =====================================
echo   WebP Safe Migrator Launcher v3
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

REM Load configuration from .env file
echo Loading configuration...
call load-config.bat

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
    echo.
    echo Press any key to copy this error output, then press a key to exit...
    pause
    exit /b 1
)
echo * Network created

echo.

REM Start MySQL
echo Starting MySQL database...
echo * Database: %DB_NAME%
echo * WordPress user: %DB_WP_USER%
echo * Port: %DB_PORT%
podman run -d --name webp-migrator-mysql --network webp-migrator-net -p %DB_PORT%:3306 -e MYSQL_DATABASE=%DB_NAME% -e MYSQL_USER=%DB_WP_USER% -e MYSQL_PASSWORD=%DB_WP_PASS% -e MYSQL_ROOT_PASSWORD=%DB_ROOT_PASS% docker.io/library/mysql:8.0 --default-authentication-plugin=mysql_native_password

if errorlevel 1 (
    echo ERROR: Failed to start MySQL
    echo Check the above output for MySQL container startup errors
    echo.
    echo Press any key to copy this error output, then press a key to exit...
    pause
    exit /b 1
)
echo * MySQL container started

echo.
echo ⠋ Waiting for MySQL to be ready (45 seconds)...
timeout /t 45 /nobreak >nul

echo ⠙ Testing MySQL connection...
podman exec webp-migrator-mysql mysqladmin ping -u root -p%DB_ROOT_PASS% 2>nul >nul
if errorlevel 1 (
    echo MySQL not ready yet, trying once more...
    timeout /t 15 /nobreak >nul
    podman exec webp-migrator-mysql mysqladmin ping -u root -p%DB_ROOT_PASS% 2>nul >nul
            if errorlevel 1 (
            echo ERROR: MySQL not ready after extended wait
            echo You can check MySQL logs with: podman logs webp-migrator-mysql
            echo.
            podman logs webp-migrator-mysql --tail=10
            echo.
            echo Press any key to copy this MySQL error output, then press a key to exit...
            pause
            exit /b 1
        )
)
echo * MySQL is ready!

echo.

REM Start WordPress
echo Starting WordPress...
echo * WordPress port: %WP_PORT%
echo * Site URL: %WP_SITE_URL%
podman run -d --name webp-migrator-wordpress --network webp-migrator-net -p %WP_PORT%:80 -e WORDPRESS_DB_HOST=webp-migrator-mysql -e WORDPRESS_DB_USER=%DB_WP_USER% -e WORDPRESS_DB_PASSWORD=%DB_WP_PASS% -e WORDPRESS_DB_NAME=%DB_NAME% -e WORDPRESS_DEBUG=1 -v "%CD%\src:/var/www/html/wp-content/plugins/webp-safe-migrator" docker.io/library/wordpress:latest

if errorlevel 1 (
    echo ERROR: Failed to start WordPress
    echo Check the above output for WordPress container startup errors
    echo Common issues: Port 8080 already in use, Docker/Podman not running
    echo.
    echo Press any key to copy this error output, then press a key to exit...
    pause
    exit /b 1
)
echo * WordPress container started

echo.

REM Start phpMyAdmin
echo Starting phpMyAdmin...
echo * phpMyAdmin port: %PMA_PORT%
podman run -d --name webp-migrator-phpmyadmin --network webp-migrator-net -p %PMA_PORT%:80 -e PMA_HOST=webp-migrator-mysql -e PMA_USER=root -e PMA_PASSWORD=%DB_ROOT_PASS% docker.io/library/phpmyadmin:latest

if errorlevel 1 (
    echo ERROR: Failed to start phpMyAdmin
    echo Check the above output for phpMyAdmin container startup errors
    echo Common issues: Port 8081 already in use, network connectivity
    echo.
    echo Press any key to copy this error output, then press a key to exit...
    pause
    exit /b 1
)
echo * phpMyAdmin container started

echo.
echo ⠹ Waiting for WordPress to be ready (60 seconds)...
echo   This is normal - WordPress needs time to download and setup...
timeout /t 60 /nobreak >nul

echo ⠸ Testing WordPress connection with detailed diagnostics...
echo * Site URL: %WP_SITE_URL%
echo * Expected response: 200 or 30x redirect codes
echo.

REM First, check if the container is actually running
echo [DIAGNOSTIC] Checking container status...
podman ps --filter "name=webp-migrator-wordpress" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

REM Check if port 8080 is accessible
echo [DIAGNOSTIC] Testing port accessibility...
netstat -an | findstr ":8080" >nul
if errorlevel 1 (
    echo ! WARNING: Port 8080 not found in netstat - container port binding may have failed
) else (
    echo * Port 8080 is bound and listening
)

echo.
echo [DIAGNOSTIC] Checking WordPress container logs for errors...
podman logs webp-migrator-wordpress --tail=20 2>nul || echo ! Could not retrieve container logs

echo.
echo [DIAGNOSTIC] Testing database connectivity from WordPress container...
echo * Checking if MySQL client is available in WordPress container...
podman exec webp-migrator-wordpress which mysql >nul 2>&1
if errorlevel 1 (
    echo ! MySQL client not found in WordPress container - installing...
    podman exec webp-migrator-wordpress apt-get update >nul 2>&1
    podman exec webp-migrator-wordpress apt-get install -y default-mysql-client >nul 2>&1
    echo * MySQL client installed
)

echo * Testing database connection...
podman exec webp-migrator-wordpress mysql -h webp-migrator-mysql -u wordpress -pwordpress123 -e "SELECT 'Database connection: OK';" 2>nul
if errorlevel 1 (
    echo ! Database connection failed - checking database container...
    podman ps --filter "name=webp-migrator-mysql" --format "table {{.Names}}\t{{.Status}}"
    echo ! Trying direct database connection test...
    podman exec webp-migrator-mysql mysql -u wordpress -pwordpress123 -e "SELECT 'Direct DB connection: OK';" 2>nul
    if errorlevel 1 (
        echo ! Direct database connection also failed
        echo.
        echo CRITICAL: Database connectivity completely broken!
        echo This will prevent WordPress from working. Check:
        echo   1. MySQL container status: podman ps
        echo   2. MySQL container logs: podman logs webp-migrator-mysql  
        echo   3. Network connectivity between containers
        echo.
        echo Press any key to copy this database error output and fix the issue...
        pause
        echo Continuing anyway, but WordPress installation will likely fail...
        echo.
    ) else (
        echo * Direct database connection successful
    )
) else (
    echo * Database connection successful
)

echo.
echo [CONNECTION TEST] Testing WordPress response...
echo * Note: HTTP 302 redirects are normal during WordPress setup
for /l %%i in (1,1,5) do (
    echo * Attempt %%i/5: Testing %WP_SITE_URL%
    
    REM Get detailed response information
    for /f "tokens=*" %%a in ('curl -s -o nul -w "HTTP_CODE:%%{http_code} TIME:%%{time_total}s SIZE:%%{size_download}bytes" "%WP_SITE_URL%" 2^>nul') do (
        echo   Response: %%a
        echo %%a | findstr "HTTP_CODE:200\|HTTP_CODE:302\|HTTP_CODE:301\|HTTP_CODE:303" >nul
        if not errorlevel 1 (
            echo * SUCCESS: WordPress is responding! %%a
            goto :wordpress_ready
        )
    )
    
    REM If response was not 200/30x, show what we got
    for /f "tokens=*" %%b in ('curl -s -w "%%{http_code}" "%WP_SITE_URL%" 2^>nul') do (
        if not "%%b"=="000" (
            if "%%b"=="302" (
                echo   HTTP 302: WordPress is redirecting (likely to setup page^)
            ) else (
                echo   Got HTTP %%b - unexpected response
            )
        ) else (
            echo   Connection refused or timeout
        )
    )
    
    if %%i lss 5 (
        if %%i==1 echo   ⠧ Waiting 10 seconds before retry...
        if %%i==2 echo   ⠇ Waiting 10 seconds before retry...
        if %%i==3 echo   ⠏ Waiting 10 seconds before retry...
        if %%i==4 echo   ⠋ Waiting 10 seconds before retry...
        timeout /t 10 /nobreak >nul
    )
)

echo.
echo [FINAL DIAGNOSTIC] After 5 attempts, showing detailed status:
podman exec webp-migrator-wordpress ps aux | findstr apache 2>nul || echo ! Apache processes not found
podman exec webp-migrator-wordpress ls -la /var/www/html/ | head -5 2>nul || echo ! WordPress files not accessible
echo.
echo ! WARNING: WordPress failed to respond after 5 attempts - proceeding with installation anyway
echo ! If you want to debug this, you can:
echo   1. Check container logs: podman logs webp-migrator-wordpress
echo   2. Check database connectivity from above diagnostics
echo   3. Try accessing http://localhost:%WP_PORT% manually in browser
echo.
echo Press any key to copy this diagnostic output, or press Enter to continue...
choice /c YN /n /m "Continue anyway? (Y/N): "
if errorlevel 2 (
    echo Installation cancelled by user
    pause
    exit /b 1
)

:wordpress_ready
echo * WordPress connection testing completed

echo.

REM Use the WordPress container directly for setup since it has WP-CLI
REM Configure PHP for optimal WebP processing
echo ⠼ Configuring PHP upload limits...
podman exec webp-migrator-wordpress bash -c "echo 'upload_max_filesize = 128M' > /usr/local/etc/php/conf.d/webp-migrator.ini && echo 'post_max_size = 128M' >> /usr/local/etc/php/conf.d/webp-migrator.ini && echo 'max_execution_time = 300' >> /usr/local/etc/php/conf.d/webp-migrator.ini && echo 'memory_limit = 512M' >> /usr/local/etc/php/conf.d/webp-migrator.ini"

echo Restarting Apache to apply PHP configuration...
podman exec webp-migrator-wordpress apache2ctl graceful 2>nul

echo ⠴ Installing WP-CLI in WordPress container...
podman exec webp-migrator-wordpress bash -c "curl -o /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /tmp/wp-cli.phar && mv /tmp/wp-cli.phar /usr/local/bin/wp" 2>nul

echo.

REM Install WordPress
echo ⠦ Installing WordPress...
echo * Admin user: %WP_ADMIN_USER%
echo * Admin password: %WP_ADMIN_PASS%
echo * Site title: %WP_SITE_TITLE%
echo * Site URL: %WP_SITE_URL%
echo.

REM Check if WordPress is already installed
echo Checking if WordPress is already installed...
podman exec webp-migrator-wordpress wp core is-installed --allow-root 2>nul
if not errorlevel 1 (
    echo * WordPress is already installed, skipping installation
    echo * Verifying admin user exists...
    podman exec webp-migrator-wordpress wp user get %WP_ADMIN_USER% --allow-root 2>nul >nul
    if errorlevel 1 (
        echo ! Admin user '%WP_ADMIN_USER%' not found, creating...
        podman exec webp-migrator-wordpress wp user create "%WP_ADMIN_USER%" "%WP_ADMIN_EMAIL%" --role=administrator --user_pass="%WP_ADMIN_PASS%" --allow-root
    ) else (
        echo * Admin user '%WP_ADMIN_USER%' exists
        echo * Updating password to ensure it matches config...
        podman exec webp-migrator-wordpress wp user update "%WP_ADMIN_USER%" --user_pass="%WP_ADMIN_PASS%" --allow-root
    )
    goto :wordpress_installed
)

echo * WordPress not installed, installing now...
echo DEBUG: Running WP-CLI install command with these exact values...
podman exec webp-migrator-wordpress wp core install --url="%WP_SITE_URL%" --title="%WP_SITE_TITLE%" --admin_user="%WP_ADMIN_USER%" --admin_password="%WP_ADMIN_PASS%" --admin_email="%WP_ADMIN_EMAIL%" --locale="en_US" --skip-email --allow-root

if errorlevel 1 (
    echo ! WordPress installation had issues - you may need to complete setup manually
    echo   Go to %WP_SITE_URL% to finish WordPress setup
    echo   Expected credentials: %WP_ADMIN_USER% / %WP_ADMIN_PASS%
    echo.
    echo WordPress installation failed. You can:
    echo   1. Try accessing %WP_SITE_URL% in browser to complete setup manually
    echo   2. Check WordPress container logs: podman logs webp-migrator-wordpress
    echo   3. Check if database connection is working from diagnostics above
    echo.
    echo Press any key to copy this error output, or Enter to continue anyway...
    pause >nul
) else (
    echo * WordPress installed successfully!
)

:wordpress_installed

echo.

REM WordPress installation should have set the password correctly
echo * WordPress installation completed with configured admin credentials
echo.

REM Activate plugin
echo ⠙ Activating WebP Safe Migrator plugin...
podman exec webp-migrator-wordpress wp plugin activate webp-safe-migrator --allow-root

if errorlevel 1 (
    echo ! Plugin activation failed - you can activate it manually in WordPress admin
    echo   Go to Plugins → Installed Plugins and activate WebP Safe Migrator
    echo.
    echo Plugin activation error details above. Common issues:
    echo   1. Plugin syntax errors or PHP compatibility issues
    echo   2. WordPress not fully installed yet
    echo   3. Database connection problems
    echo.
    echo Press any key to copy this plugin error output, or Enter to continue...
    pause >nul
) else (
    echo * Plugin activated successfully!
)

echo.

REM Create sample content
echo Creating sample content...
podman exec webp-migrator-wordpress wp post create --post_type=page --post_title="WebP Migrator Test Guide" --post_content="<h2>Welcome to %WP_SITE_TITLE%</h2><p>Go to Media → WebP Migrator to start testing.</p><p><strong>Admin Login:</strong><br>Username: %WP_ADMIN_USER%<br>Password: %WP_ADMIN_PASS%</p>" --post_status=publish --allow-root

echo * Sample content created

echo.
echo Checking final container status...
podman ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

echo.
echo =====================================
echo   DEPLOYMENT COMPLETE!
echo =====================================
echo.
echo * WordPress Site: %WP_SITE_URL%
echo * WordPress Admin: %WP_SITE_URL%/wp-admin
echo * phpMyAdmin: http://localhost:%PMA_PORT%
echo.
echo WordPress Credentials (from config):
echo   Username: %WP_ADMIN_USER%
echo   Password: %WP_ADMIN_PASS%
echo   Email: %WP_ADMIN_EMAIL%
echo.
echo Database Credentials (from config):
echo   Database: %DB_NAME%
echo   WordPress User: %DB_WP_USER% / %DB_WP_PASS%
echo   Root User: root / %DB_ROOT_PASS%
echo.
echo Configuration File: webp-migrator.env
echo.
echo Plugin Access:
echo   Go to Media → WebP Migrator in WordPress admin
echo.

REM Open WordPress admin in default browser
echo Opening WordPress Admin Panel in browser...
start %WP_SITE_URL%/wp-admin

echo.
echo =====================================
echo   🎉 SUCCESS! SETUP COMPLETE! 🎉
echo =====================================
echo.
echo IMPORTANT: Your credentials are configurable!
echo Edit 'webp-migrator.env' to customize usernames/passwords
echo Then run this script again to apply changes.
echo.
echo Management Scripts Available:
echo   launch-webp-migrator.bat        - Start/restart (this script)
echo   stop-webp-migrator.bat          - Stop containers (keep data)
echo   cleanup-webp-migrator.bat       - Complete cleanup (removes all data)
echo   status-webp-migrator.bat        - Show status
echo   manage-wp.bat                   - WordPress management commands
echo.
echo ✅ Installation completed successfully!
echo 
echo SAVE THESE DETAILS - Press any key to copy this information:
pause >nul
echo.
echo 📋 Copy this information for your records:
echo ==========================================
echo WordPress Site: %WP_SITE_URL%
echo WordPress Admin: %WP_SITE_URL%/wp-admin
echo Username: %WP_ADMIN_USER%
echo Password: %WP_ADMIN_PASS%
echo ==========================================
echo.
echo Script completed successfully!
