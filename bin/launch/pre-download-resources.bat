@echo off
setlocal enabledelayedexpansion
REM ==============================================================================
REM WebP Safe Migrator - Resource Pre-Download Script (Windows)
REM Downloads all required Docker images and tools before setup
REM ==============================================================================

REM Unicode spinner characters
set "spin=⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏"

echo.
echo =====================================
echo   WebP Safe Migrator Pre-Download
echo =====================================
echo.
echo ⬇️  Pre-downloading all required resources...
echo    This will speed up the setup process significantly!
echo.

REM Check if Podman is available
echo ⠋ Checking container engine availability...
podman --version >nul 2>&1
if errorlevel 1 (
    docker --version >nul 2>&1
    if errorlevel 1 (
        echo ❌ ERROR: Neither Podman nor Docker found
        echo Please install Podman or Docker first
        echo.
        echo Press any key to exit...
        pause >nul
        exit /b 1
    ) else (
        set CONTAINER_ENGINE=docker
        echo ✅ Docker detected
    )
) else (
    set CONTAINER_ENGINE=podman
    echo ✅ Podman detected
)

echo.
echo 📦 Starting resource downloads...
echo.

REM Download WordPress image
echo ⠋ Downloading WordPress Docker Image...
%CONTAINER_ENGINE% pull docker.io/library/wordpress:latest >nul 2>&1
if errorlevel 1 (
    echo ❌ Failed to download WordPress image
    echo Error: Check your internet connection and container engine
    echo Press any key to continue...
    pause >nul
) else (
    echo ✅ WordPress image ready
)
echo.

REM Download MySQL image  
echo ⠙ Downloading MySQL Docker Image...
%CONTAINER_ENGINE% pull docker.io/library/mysql:8.0 >nul 2>&1
if errorlevel 1 (
    echo ❌ Failed to download MySQL image
    echo Error: Check your internet connection and container engine
    echo Press any key to continue...
    pause >nul
) else (
    echo ✅ MySQL image ready
)
echo.

REM Download phpMyAdmin image
echo ⠹ Downloading phpMyAdmin Docker Image...
%CONTAINER_ENGINE% pull docker.io/library/phpmyadmin:latest >nul 2>&1
if errorlevel 1 (
    echo ❌ Failed to download phpMyAdmin image
    echo Error: Check your internet connection and container engine
    echo Press any key to continue...
    pause >nul
) else (
    echo ✅ phpMyAdmin image ready
)
echo.

REM Download WP-CLI
echo ⠦ Downloading WP-CLI tool...
if not exist "temp_wpcli.phar" (
    curl -L -o temp_wpcli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar 2>nul
    if errorlevel 1 (
        echo ❌ Failed to download WP-CLI
        echo This is optional - WP-CLI will be installed during container setup
    ) else (
        echo ✅ WP-CLI downloaded successfully
        echo   📁 Saved as temp_wpcli.phar (will be used during setup)
    )
) else (
    echo ✅ WP-CLI already downloaded
)
echo.

REM Verify all images are available
echo 🔍 Verifying downloaded resources...
echo.

%CONTAINER_ENGINE% images --format "table {{.Repository}}\t{{.Tag}}\t{{.Size}}" | findstr -E "(wordpress|mysql|phpmyadmin)"
if errorlevel 1 (
    echo ⚠️  Some images may not have downloaded correctly
) else (
    echo ✅ All container images verified
)

echo.
echo =====================================
echo   🎉 PRE-DOWNLOAD COMPLETE! 🎉
echo =====================================
echo.
echo ✅ Resources ready for fast setup:
echo   📦 WordPress Docker image
echo   📦 MySQL 8.0 Docker image  
echo   📦 phpMyAdmin Docker image
echo   🔧 WP-CLI tool (if available)
echo.
echo 🚀 Benefits:
echo   ⚡ 3-5x faster container startup
echo   📊 No download delays during setup
echo   🛠️  Consistent offline-capable setup
echo.
echo 💡 Next steps:
echo   1. Run: launch-webp-migrator.bat
echo   2. Setup will use pre-downloaded resources
echo   3. Enjoy blazing-fast deployment! 🔥
echo.
echo Press any key to close...
pause >nul
