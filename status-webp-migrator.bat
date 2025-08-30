@echo off
REM ==============================================================================
REM WebP Safe Migrator - Windows Status Checker
REM Checks the status of all containers and services
REM ==============================================================================

echo.
echo =====================================
echo   WebP Safe Migrator Status
echo =====================================
echo.

REM Check if Podman is available
podman --version >nul 2>&1
if errorlevel 1 (
    echo ❌ Podman not found
    echo    Install from: https://podman.io/getting-started/installation
    pause
    exit /b 1
) else (
    echo ✓ Podman is available
)

echo.

REM Check container status
echo Container Status:
echo -----------------
podman ps -a --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" | findstr webp-migrator
if errorlevel 1 (
    echo No WebP Safe Migrator containers found.
    echo Run launch-simple.bat to start the environment.
) else (
    echo.
    echo Running Containers:
    echo ------------------
    podman ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" | findstr webp-migrator
    if errorlevel 1 (
        echo No containers currently running.
        echo Run launch-simple.bat to start the environment.
    )
)

echo.

REM Check network
echo Network Status:
echo ---------------
podman network ls | findstr webp-migrator-net >nul
if errorlevel 1 (
    echo ❌ WebP Migrator network not found
) else (
    echo ✓ WebP Migrator network exists
)

echo.

REM Check volumes
echo Volume Status:
echo --------------
podman volume ls | findstr wordpress_data >nul
if errorlevel 1 (
    echo ! WordPress data volume not found (first run or cleaned up)
) else (
    echo ✓ WordPress data volume exists
)

echo.

REM Check service accessibility
echo Service Accessibility:
echo ----------------------

REM Check WordPress
echo Checking WordPress (http://localhost:8080)...
curl -s -o nul -w "%%{http_code}" "http://localhost:8080" >nul 2>&1
if not errorlevel 1 (
    for /f %%i in ('curl -s -o nul -w "%%{http_code}" "http://localhost:8080"') do set wordpress_status=%%i
    if "!wordpress_status!"=="200" (
        echo ✓ WordPress is accessible and running
    ) else if "!wordpress_status:~0,1!"=="3" (
        echo ✓ WordPress is accessible (redirecting)
    ) else (
        echo ! WordPress returned status: !wordpress_status!
    )
) else (
    echo ❌ WordPress is not accessible
)

REM Check phpMyAdmin
echo Checking phpMyAdmin (http://localhost:8081)...
curl -s -o nul -w "%%{http_code}" "http://localhost:8081" >nul 2>&1
if not errorlevel 1 (
    for /f %%i in ('curl -s -o nul -w "%%{http_code}" "http://localhost:8081"') do set phpmyadmin_status=%%i
    if "!phpmyadmin_status!"=="200" (
        echo ✓ phpMyAdmin is accessible and running
    ) else if "!phpmyadmin_status:~0,1!"=="3" (
        echo ✓ phpMyAdmin is accessible (redirecting)
    ) else (
        echo ! phpMyAdmin returned status: !phpmyadmin_status!
    )
) else (
    echo ❌ phpMyAdmin is not accessible
)

REM Check MySQL
echo Checking MySQL database connection...
podman exec webp-migrator-mysql mysqladmin ping -u root -proot123 2>nul >nul
if not errorlevel 1 (
    echo ✓ MySQL database is accessible
) else (
    echo ❌ MySQL database is not accessible
)

echo.

REM Show quick access information
echo Quick Access URLs:
echo -----------------
echo WordPress Site: http://localhost:8080
echo WordPress Admin: http://localhost:8080/wp-admin
echo phpMyAdmin: http://localhost:8081
echo.
echo Default Credentials:
echo WordPress: admin / admin123!
echo Database: wordpress / wordpress123
echo DB Root: root / root123
echo.

REM Show available actions
echo Available Actions:
echo -----------------
echo launch-webp-migrator.bat   - Start/restart the environment
echo stop-webp-migrator.bat     - Stop containers (keep data)
echo cleanup-webp-migrator.bat  - Complete cleanup (removes all data)
echo status-webp-migrator.bat   - Show this status (current script)
echo.
echo Status check completed!
