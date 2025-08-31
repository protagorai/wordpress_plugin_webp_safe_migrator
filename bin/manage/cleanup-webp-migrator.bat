@echo off
REM ==============================================================================
REM WebP Safe Migrator - Windows Complete Cleanup Script
REM WARNING: This removes ALL containers and data!
REM ==============================================================================

echo.
echo =====================================
echo   WebP Safe Migrator COMPLETE CLEANUP
echo =====================================
echo.
echo WARNING: This will remove ALL containers and data!
echo This action cannot be undone.
echo.
set /p confirm=Are you sure you want to continue? (y/N): 
if /i not "%confirm%"=="y" (
    echo Cleanup cancelled.
    pause
    exit /b 0
)

echo.
echo Performing complete cleanup...
echo.

REM Stop all containers
echo Stopping all containers...
podman stop webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin 2>nul

REM Remove all containers
echo Removing all containers...
podman rm -f webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin 2>nul
if not errorlevel 1 (
    echo ✓ Containers removed
) else (
    echo ! Some containers may not have existed
)

REM Remove volumes
echo Removing volumes...
podman volume rm wordpress_data 2>nul
if not errorlevel 1 (
    echo ✓ WordPress data volume removed
) else (
    echo ! WordPress data volume may not have existed
)

REM Remove network
echo Removing network...
podman network rm webp-migrator-net 2>nul
if not errorlevel 1 (
    echo ✓ Network removed
) else (
    echo ! Network may not have existed
)

echo.
echo =====================================
echo   COMPLETE CLEANUP FINISHED
echo =====================================
echo.
echo All WebP Safe Migrator containers, volumes, and networks have been removed.
echo To start fresh, run: launch-webp-migrator.bat
echo.
echo Cleanup completed successfully!
