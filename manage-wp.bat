@echo off
REM ==============================================================================
REM WebP Safe Migrator - WordPress Management Utility
REM Provides common WordPress CLI commands for development
REM ==============================================================================

echo.
echo =====================================
echo   WordPress Management Utility
echo =====================================
echo.

REM Check if WordPress container is running
podman ps --format "{{.Names}}" | findstr webp-migrator-wpcli >nul
if errorlevel 1 (
    echo ERROR: WP-CLI container not running
    echo Run launch-webp-migrator.bat first
    exit /b 1
)

if "%1"=="" (
    goto show_usage
)

set action=%1
shift

if "%action%"=="plugins" goto list_plugins
if "%action%"=="plugin-status" goto plugin_status  
if "%action%"=="activate" goto activate_plugin
if "%action%"=="deactivate" goto deactivate_plugin
if "%action%"=="wp-info" goto wp_info
if "%action%"=="db-check" goto db_check
if "%action%"=="cache-flush" goto cache_flush
if "%action%"=="help" goto show_usage
goto show_usage

:list_plugins
echo Listing WordPress plugins...
podman exec webp-migrator-wpcli wp plugin list --format=table --allow-root
goto end

:plugin_status
if "%1"=="" (
    echo Checking WebP Safe Migrator status...
    podman exec webp-migrator-wpcli wp plugin status webp-safe-migrator --allow-root
) else (
    echo Checking %1 plugin status...
    podman exec webp-migrator-wpcli wp plugin status %1 --allow-root
)
goto end

:activate_plugin
if "%1"=="" (
    echo Activating WebP Safe Migrator...
    podman exec webp-migrator-wpcli wp plugin activate webp-safe-migrator --allow-root
) else (
    echo Activating %1 plugin...
    podman exec webp-migrator-wpcli wp plugin activate %1 --allow-root
)
goto end

:deactivate_plugin
if "%1"=="" (
    echo Deactivating WebP Safe Migrator...
    podman exec webp-migrator-wpcli wp plugin deactivate webp-safe-migrator --allow-root
) else (
    echo Deactivating %1 plugin...
    podman exec webp-migrator-wpcli wp plugin deactivate %1 --allow-root
)
goto end

:wp_info
echo WordPress Installation Info:
echo ----------------------------
podman exec webp-migrator-wpcli wp core version --allow-root
podman exec webp-migrator-wpcli wp core check-update --allow-root
echo.
echo WordPress Configuration:
podman exec webp-migrator-wpcli wp config list --allow-root
goto end

:db_check
echo Database Connection Test:
echo ------------------------
podman exec webp-migrator-wpcli wp db check --allow-root
echo.
echo Database Size:
podman exec webp-migrator-wpcli wp db size --allow-root
goto end

:cache_flush
echo Flushing WordPress caches...
podman exec webp-migrator-wpcli wp cache flush --allow-root
podman exec webp-migrator-wpcli wp rewrite flush --allow-root
echo * WordPress caches flushed
goto end

:show_usage
echo WordPress Management Utility
echo.
echo Usage: manage-wp.bat [ACTION] [PLUGIN-NAME]
echo.
echo Actions:
echo   plugins           - List all plugins
echo   plugin-status     - Check plugin status (WebP Migrator or specified plugin)
echo   activate          - Activate plugin (WebP Migrator or specified plugin)
echo   deactivate        - Deactivate plugin (WebP Migrator or specified plugin)
echo   wp-info           - WordPress version and configuration info
echo   db-check          - Database connectivity and size check
echo   cache-flush       - Clear WordPress caches
echo   help              - Show this help message
echo.
echo Examples:
echo   manage-wp.bat plugins                    # List all plugins
echo   manage-wp.bat plugin-status              # Check WebP Migrator status
echo   manage-wp.bat activate                   # Activate WebP Migrator
echo   manage-wp.bat activate query-monitor     # Activate Query Monitor plugin
echo   manage-wp.bat wp-info                    # WordPress version info
echo   manage-wp.bat cache-flush                # Clear caches
echo.

:end
