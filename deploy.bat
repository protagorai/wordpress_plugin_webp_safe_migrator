@echo off
setlocal enabledelayedexpansion
REM ==============================================================================
REM Multi-Plugin WordPress Development Environment - Main Deployment Script
REM Configuration-driven deployment supporting multiple plugins
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
if /i "%1"=="plugins" goto plugins
if /i "%1"=="help" goto show_help

echo ❌ Unknown command: %1
goto show_help

:show_help
echo.
echo =====================================
echo    Multi-Plugin WordPress Dev Environment v2.0
echo =====================================
echo.
echo 🚀 Multi-Plugin WordPress Development Environment
echo.
echo COMMANDS:
echo   start       Start the development environment
echo   stop        Stop all containers (keep data)
echo   restart     Stop and start the environment  
echo   clean       Complete cleanup (removes all data)
echo   status      Show current container status
echo   download    Pre-download resources for faster setup
echo   manage      WordPress management utilities
echo   plugins     Multi-plugin management commands
echo   fix         Fix upload permissions (if uploads fail)
echo   help        Show this help message
echo.
echo EXAMPLES:
echo   deploy start                        # Start multi-plugin environment
echo   deploy stop                         # Stop containers
echo   deploy clean                        # Clean slate
echo   deploy plugins list                 # List available plugins
echo   deploy plugins activate             # Show plugin status  
echo   deploy plugins activate PLUGIN     # Activate specific plugin
echo   deploy plugins status               # Check WordPress plugin status
echo   deploy fix                          # Fix upload permissions
echo.
echo MULTI-PLUGIN MANAGEMENT:
echo   setup\multi-plugin-manager.ps1 list        # List available plugins
echo   setup\multi-plugin-manager.ps1 status      # Show plugin deployment status
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
    
    REM Deploy plugins using configuration-driven deployment
    echo Deploying plugins using configuration from bin/config/plugins.yaml...
    
    REM Use configuration-driven deployment script
    echo * Reading deployment configuration for development profile...
    powershell -ExecutionPolicy Bypass -File "setup\deploy-plugins-to-container.ps1" -ContainerName "webp-migrator-wordpress" -Profile "development"
    
    if errorlevel 1 (
        echo ! Configuration-driven deployment failed, falling back to manual plugin installation...
        
        REM Fallback: Copy and activate plugins manually  
        echo * Manual plugin deployment fallback...
        for /d %%i in (src\*) do (
            if exist "%%i\*.php" (
                echo   - Installing plugin: %%~ni
                podman exec webp-migrator-wordpress rm -rf "/var/www/html/wp-content/plugins/%%~ni" 2>nul
                podman cp "%%i" webp-migrator-wordpress:/var/www/html/wp-content/plugins/%%~ni 2>nul
                
                if not errorlevel 1 (
                    echo     ✓ Plugin %%~ni copied successfully
                    
                    REM Fix permissions  
                    podman exec webp-migrator-wordpress chown -R www-data:www-data "/var/www/html/wp-content/plugins/%%~ni" 2>nul
                    
                    REM Activate plugins based on development profile defaults
                    if "%%~ni"=="okvir-image-safe-migrator" (
                        echo     * Activating primary plugin...
                        podman exec webp-migrator-wordpress wp plugin activate %%~ni --allow-root 2>nul
                        if not errorlevel 1 (
                            echo     ✓ Plugin %%~ni activated
                        ) else (
                            echo     ! Plugin %%~ni activation failed
                        )
                    ) else if "%%~ni"=="example-second-plugin" (
                        echo     * Activating example plugin...
                        podman exec webp-migrator-wordpress wp plugin activate %%~ni --allow-root 2>nul
                        if not errorlevel 1 (
                            echo     ✓ Plugin %%~ni activated
                        ) else (
                            echo     ! Plugin %%~ni activation failed
                        )
                    ) else (
                        echo     ○ Plugin %%~ni deployed but not activated
                    )
                ) else (
                    echo     ✗ Failed to copy plugin %%~ni
                )
            )
        )
    ) else (
        echo * Configuration-driven plugin deployment completed successfully!
    )
)

echo.
echo =====================================
echo     SUCCESS - Multi-Plugin Environment Ready!
echo =====================================
echo.
echo  WordPress: %WP_SITE_URL%/wp-admin
echo  Username:  %WP_ADMIN_USER%
echo  Password:  %WP_ADMIN_PASS%
echo.
echo  phpMyAdmin: http://localhost:%PMA_PORT%
echo  Primary Plugin: Media → Image Migrator
echo  Plugin Management: setup\multi-plugin-manager.ps1
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
echo   deploy.bat start           # Start/restart multi-plugin environment
echo   deploy.bat stop            # Stop containers
echo   deploy.bat clean           # Complete cleanup
echo   deploy.bat status          # Show status
echo   deploy.bat download        # Pre-download resources
echo   deploy.bat plugins list    # List available plugins
echo   deploy.bat fix             # Fix upload permissions manually
echo.
echo Multi-Plugin Management:
echo   setup\multi-plugin-manager.ps1 list                    # List available plugins
echo   setup\multi-plugin-manager.ps1 install-all --profile development  # Deploy development plugins
echo   setup\multi-plugin-manager.ps1 status                 # Show plugin status
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

:plugins
echo 🔌 Multi-plugin management...
if "%2"=="" (
    powershell -ExecutionPolicy Bypass -File "setup\clean-plugin-list.ps1" -Action "list"
) else if "%2"=="deploy" (
    echo * Deploying plugins to running container...
    powershell -ExecutionPolicy Bypass -File "setup\deploy-plugins-to-container.ps1" -ContainerName "webp-migrator-wordpress" -Profile "development"
) else if "%2"=="activate" (
    if "%3"=="" (
        echo * Showing current plugin status...
        powershell -ExecutionPolicy Bypass -File "setup\activate-plugin-manually.ps1"
    ) else (
        echo * Activating plugin: %3
        powershell -ExecutionPolicy Bypass -File "setup\activate-plugin-manually.ps1" -PluginSlug "%3"
    )
) else if "%2"=="status" (
    echo * Checking plugin status in WordPress...
    podman exec webp-migrator-wordpress wp plugin list --allow-root 2>nul
) else (
    echo Available plugin commands:
    echo   list       - List available plugins
    echo   deploy     - Deploy plugins to running container
    echo   activate   - Show plugin status or activate specific plugin
    echo   status     - Show WordPress plugin status
    echo.
    echo Examples:
    echo   deploy.bat plugins list
    echo   deploy.bat plugins activate
    echo   deploy.bat plugins activate example-second-plugin
    echo   deploy.bat plugins status
    powershell -ExecutionPolicy Bypass -File "setup\clean-plugin-list.ps1" -Action "list"
)
goto end

:fix
echo 🛠️ Fixing upload permissions...
call bin\manage\fix-uploads-ownership.bat
goto end

:end
