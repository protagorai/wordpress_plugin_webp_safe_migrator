@echo off
setlocal enabledelayedexpansion
REM Load configuration from webp-migrator.env file
REM This sets environment variables that can be used by other batch files

REM Set default values first
set "WP_ADMIN_USER=admin"
set "WP_ADMIN_PASS=admin123!"
set "WP_ADMIN_EMAIL=admin@webp-test.local"
set "DB_WP_USER=wordpress"
set "DB_WP_PASS=wordpress123"
set "DB_ROOT_PASS=root123"
set "DB_NAME=wordpress_webp_test"
set "WP_PORT=8080"
set "DB_PORT=3307"
set "PMA_PORT=8081"
set "WP_SITE_TITLE=WebP Migrator Test Site"
set "WP_SITE_URL=http://localhost:8080"

REM Load from config file if it exists
if exist "webp-migrator.env" (
    echo * Loading configuration from: webp-migrator.env
    for /f "usebackq delims=" %%i in ("webp-migrator.env") do (
        set "line=%%i"
        REM Skip comments and empty lines
        if not "!line!"=="" if not "!line:~0,1!"=="#" (
            REM Parse key=value pairs
            for /f "tokens=1,* delims==" %%a in ("!line!") do (
                set "%%a=%%b"
            )
        )
    )
    echo * Configuration loaded successfully
) else (
    echo * Config file not found, using defaults
    echo * You can create 'webp-migrator.env' to customize settings
)

REM Export variables to parent scope
endlocal & (
    set "WP_ADMIN_USER=%WP_ADMIN_USER%"
    set "WP_ADMIN_PASS=%WP_ADMIN_PASS%"
    set "WP_ADMIN_EMAIL=%WP_ADMIN_EMAIL%"
    set "DB_WP_USER=%DB_WP_USER%"
    set "DB_WP_PASS=%DB_WP_PASS%"
    set "DB_ROOT_PASS=%DB_ROOT_PASS%"
    set "DB_NAME=%DB_NAME%"
    set "WP_PORT=%WP_PORT%"
    set "DB_PORT=%DB_PORT%"
    set "PMA_PORT=%PMA_PORT%"
    set "WP_SITE_TITLE=%WP_SITE_TITLE%"
    set "WP_SITE_URL=%WP_SITE_URL%"
)

REM Display loaded configuration
echo.
echo Configuration Summary:
echo * WordPress Admin: %WP_ADMIN_USER% / %WP_ADMIN_PASS%
echo * WordPress URL: %WP_SITE_URL%
echo * Database: %DB_NAME% (User: %DB_WP_USER%)
echo * Ports - WordPress:%WP_PORT%, MySQL:%DB_PORT%, phpMyAdmin:%PMA_PORT%
echo.
