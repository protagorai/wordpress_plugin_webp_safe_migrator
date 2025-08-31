@echo off
setlocal enabledelayedexpansion
REM WordPress Auto-Installation Script with Health Checks for Windows
REM Ensures consistent WordPress setup across all platforms

echo.
echo [WP-INSTALL] Starting WordPress auto-installation...

REM Load configuration
if exist "..\load-config.bat" (
    call ..\load-config.bat
) else (
    REM Set defaults if config not available
    set WP_ADMIN_USER=admin
    set WP_ADMIN_PASS=admin123
    set WP_ADMIN_EMAIL=admin@webp-test.local
    set WP_SITE_TITLE=WebP Migrator Test Site
    set WP_SITE_URL=http://localhost:8080
)

echo [WP-INSTALL] Admin User: %WP_ADMIN_USER%
echo [WP-INSTALL] Site URL: %WP_SITE_URL%
echo.

REM Detect compose command
set COMPOSE_FILE=docker-compose.yml
if exist "podman-compose.yml" set COMPOSE_FILE=podman-compose.yml

podman version >nul 2>&1
if not errorlevel 1 (
    set CONTAINER_ENGINE=podman
    podman-compose --version >nul 2>&1
    if not errorlevel 1 (
        set COMPOSE_CMD=podman-compose -f %COMPOSE_FILE%
    ) else (
        set COMPOSE_CMD=podman compose -f %COMPOSE_FILE%
    )
) else (
    set CONTAINER_ENGINE=docker
    docker-compose --version >nul 2>&1
    if not errorlevel 1 (
        set COMPOSE_CMD=docker-compose -f %COMPOSE_FILE%
    ) else (
        set COMPOSE_CMD=docker compose -f %COMPOSE_FILE%
    )
)

echo [WP-INSTALL] Using: %COMPOSE_CMD%
echo.

REM Wait for services to be healthy
echo [WP-INSTALL] Waiting for services to be healthy...
set /a count=0
:healthcheck_loop
%COMPOSE_CMD% ps | findstr "healthy" >nul 2>&1
if not errorlevel 1 (
    echo [WP-INSTALL] Services are healthy!
    goto :services_ready
)

set /a count+=1
if %count% geq 60 (
    echo [WP-INSTALL] Services not all healthy after 5 minutes, proceeding anyway...
    goto :services_ready
)

if %count% == 10 echo [WP-INSTALL] Still waiting for services... (%count%/60)
if %count% == 20 echo [WP-INSTALL] Still waiting for services... (%count%/60)
if %count% == 30 echo [WP-INSTALL] Still waiting for services... (%count%/60)
if %count% == 40 echo [WP-INSTALL] Still waiting for services... (%count%/60)
if %count% == 50 echo [WP-INSTALL] Still waiting for services... (%count%/60)

timeout /t 5 /nobreak >nul
goto :healthcheck_loop

:services_ready

REM Additional WordPress readiness check
echo [WP-INSTALL] Verifying WordPress is responding...
set /a wp_count=0
:wordpress_check
curl -s -o nul -w "%%{http_code}" "%WP_SITE_URL%" 2>nul | findstr "200 30" >nul
if not errorlevel 1 (
    echo [WP-INSTALL] WordPress is responding!
    goto :wordpress_ready
)

set /a wp_count+=1
if %wp_count% geq 30 (
    echo [WP-INSTALL] WordPress may not be fully ready, but proceeding with installation...
    goto :wordpress_ready
)

if %wp_count% == 5 echo [WP-INSTALL] Waiting for WordPress to respond... (%wp_count%/30)
if %wp_count% == 10 echo [WP-INSTALL] Waiting for WordPress to respond... (%wp_count%/30)
if %wp_count% == 15 echo [WP-INSTALL] Waiting for WordPress to respond... (%wp_count%/30)
if %wp_count% == 20 echo [WP-INSTALL] Waiting for WordPress to respond... (%wp_count%/30)
if %wp_count% == 25 echo [WP-INSTALL] Waiting for WordPress to respond... (%wp_count%/30)

timeout /t 3 /nobreak >nul
goto :wordpress_check

:wordpress_ready

REM Check if WordPress is already installed
echo [WP-INSTALL] Checking WordPress installation status...
%COMPOSE_CMD% exec -T wpcli wp core is-installed 2>nul
if not errorlevel 1 (
    echo [WP-INSTALL] WordPress is already installed
    
    REM Check if admin user exists
    %COMPOSE_CMD% exec -T wpcli wp user get "%WP_ADMIN_USER%" 2>nul >nul
    if not errorlevel 1 (
        echo [WP-INSTALL] Admin user '%WP_ADMIN_USER%' exists, updating password...
        %COMPOSE_CMD% exec -T wpcli wp user update "%WP_ADMIN_USER%" --user_pass="%WP_ADMIN_PASS%"
        echo [WP-INSTALL] Admin user password updated
    ) else (
        echo [WP-INSTALL] Creating admin user '%WP_ADMIN_USER%'...
        %COMPOSE_CMD% exec -T wpcli wp user create "%WP_ADMIN_USER%" "%WP_ADMIN_EMAIL%" --role=administrator --user_pass="%WP_ADMIN_PASS%"
        echo [WP-INSTALL] Admin user created
    )
    goto :install_complete
)

REM Install WordPress
echo [WP-INSTALL] Installing WordPress...
%COMPOSE_CMD% exec -T wpcli wp core install --url="%WP_SITE_URL%" --title="%WP_SITE_TITLE%" --admin_user="%WP_ADMIN_USER%" --admin_password="%WP_ADMIN_PASS%" --admin_email="%WP_ADMIN_EMAIL%" --skip-email

if errorlevel 1 (
    echo [WP-INSTALL] WordPress installation failed!
    exit /b 1
) else (
    echo [WP-INSTALL] WordPress installed successfully!
)

:install_complete

REM Activate WebP Safe Migrator plugin
echo [WP-INSTALL] Activating WebP Safe Migrator plugin...
%COMPOSE_CMD% exec -T wpcli wp plugin activate webp-safe-migrator 2>nul
if not errorlevel 1 (
    echo [WP-INSTALL] Plugin activated successfully!
) else (
    echo [WP-INSTALL] Plugin activation failed - you can activate it manually in WordPress admin
)

REM Create test content if needed
echo [WP-INSTALL] Setting up test content...
for /f %%i in ('%COMPOSE_CMD% exec -T wpcli wp post list --post_type=page --title="WebP Migrator Test Guide" --format=count 2^>nul') do set test_page_count=%%i
if "!test_page_count!" == "0" (
    echo [WP-INSTALL] Creating test page...
    %COMPOSE_CMD% exec -T wpcli wp post create --post_type=page --post_title="WebP Migrator Test Guide" --post_content="<h2>Welcome to %WP_SITE_TITLE%</h2><p>Go to Media → WebP Migrator to start testing.</p><p><strong>Login Credentials:</strong><br>Username: %WP_ADMIN_USER%<br>Password: %WP_ADMIN_PASS%</p>" --post_status=publish 2>nul
) else (
    echo [WP-INSTALL] Test page already exists
)

REM Final verification
echo [WP-INSTALL] Final verification...
curl -s -o nul -w "%%{http_code}" "%WP_SITE_URL%/wp-admin/" 2>nul | findstr "200" >nul
if not errorlevel 1 (
    echo [WP-INSTALL] WordPress admin is accessible!
) else (
    echo [WP-INSTALL] WordPress admin may not be fully ready yet
)

echo.
echo [WP-INSTALL] === WordPress Installation Complete ===
echo.
echo Site URL: %WP_SITE_URL%
echo Admin Panel: %WP_SITE_URL%/wp-admin
echo.
echo Login Credentials:
echo   Username: %WP_ADMIN_USER%
echo   Password: %WP_ADMIN_PASS%
echo   Email: %WP_ADMIN_EMAIL%
echo.
echo Plugin: WebP Safe Migrator (should be activated)
echo Go to: Media → WebP Migrator to start testing
echo.
