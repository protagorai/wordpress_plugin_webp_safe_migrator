@echo off
REM ==============================================================================
REM Multi-Plugin WordPress Environment - Windows Cleanup Script
REM Stops all containers safely
REM ==============================================================================

echo.
echo =====================================
echo   Multi-Plugin Environment Cleanup
echo =====================================
echo.

echo Stopping Multi-Plugin WordPress containers...
echo.

REM Stop all containers gracefully
echo Stopping WordPress container...
podman stop webp-migrator-wordpress 2>nul
if not errorlevel 1 (
    echo ✓ WordPress stopped
) else (
    echo ! WordPress was not running
)

echo Stopping phpMyAdmin container...
podman stop webp-migrator-phpmyadmin 2>nul
if not errorlevel 1 (
    echo ✓ phpMyAdmin stopped
) else (
    echo ! phpMyAdmin was not running
)

echo Stopping MySQL database...
podman stop webp-migrator-mysql 2>nul
if not errorlevel 1 (
    echo ✓ MySQL stopped
) else (
    echo ! MySQL was not running
)

echo.
echo All WebP Safe Migrator containers have been stopped.
echo.
echo Note: Containers are stopped but not removed.
echo       Your data is preserved.
echo       Run launch-webp-migrator.bat to start again.
echo.

REM Show remaining containers
echo Current container status:
podman ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" | findstr webp-migrator

echo.
echo Stop completed successfully!
