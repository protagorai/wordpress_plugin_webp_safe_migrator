@echo off
REM Don't enable delayed expansion initially to handle special characters
REM Load configuration from webp-migrator.env file
REM This sets environment variables that can be used by other batch files

REM Set default values first
set "WP_ADMIN_USER=admin"
set "WP_ADMIN_PASS=admin123"
set "WP_ADMIN_EMAIL=admin@webp-test.local"
set "DB_WP_USER=wpuser"
set "DB_WP_PASS=wppass"
set "DB_ROOT_PASS=root123"
set "DB_NAME=wordpress"
set "WP_PORT=8080"
set "DB_PORT=3307"
set "PMA_PORT=8081"
set "WP_SITE_TITLE=WebP Migrator Test Site"
set "WP_SITE_URL=http://localhost:8080"

REM Load from config file if it exists
if exist "webp-migrator.env" (
    echo * Loading configuration from: webp-migrator.env
    REM Parse config file without delayed expansion to handle special characters
    for /f "usebackq tokens=1,* delims==" %%a in ("webp-migrator.env") do (
        REM Skip lines starting with # (comments) or empty lines
        if not "%%a"=="" if not "%%a:~0,1%"=="#" (
            set "%%a=%%b"
        )
    )
    echo * Configuration loaded successfully
) else (
    echo * Config file not found, using defaults
    echo * You can create 'webp-migrator.env' to customize settings
)

REM Variables are now set in the current scope

REM Display loaded configuration
echo.
echo Configuration Summary:
echo * WordPress Admin: %WP_ADMIN_USER% / %WP_ADMIN_PASS%
echo * WordPress URL: %WP_SITE_URL%
echo * Database: %DB_NAME% (User: %DB_WP_USER%)
echo * Ports - WordPress:%WP_PORT%, MySQL:%DB_PORT%, phpMyAdmin:%PMA_PORT%
echo.
