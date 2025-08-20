# WordPress Development Environment Setup Script for Windows
# This script installs PHP, MySQL, and WordPress for testing the WebP Safe Migrator plugin

param(
    [string]$InstallPath = "C:\webp-migrator-test",
    [string]$WordPressVersion = "latest",
    [string]$PHPVersion = "8.1",
    [switch]$SkipDownloads,
    [switch]$StartServices
)

# Configuration
$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"

# Paths
$BasePath = $InstallPath
$PHPPath = "$BasePath\php"
$MySQLPath = "$BasePath\mysql"
$WordPressPath = "$BasePath\wordpress"
$NginxPath = "$BasePath\nginx"
$TempPath = "$BasePath\temp"

Write-Host "=== WebP Migrator WordPress Test Environment Setup ===" -ForegroundColor Green
Write-Host "Installation path: $BasePath" -ForegroundColor Yellow

# Create directories
Write-Host "Creating directories..." -ForegroundColor Cyan
New-Item -ItemType Directory -Force -Path $BasePath, $PHPPath, $MySQLPath, $WordPressPath, $NginxPath, $TempPath | Out-Null

# Download function
function Download-File {
    param(
        [string]$Url,
        [string]$OutputPath,
        [string]$Description
    )
    
    if (Test-Path $OutputPath) {
        Write-Host "$Description already exists, skipping download." -ForegroundColor Yellow
        return
    }
    
    Write-Host "Downloading $Description..." -ForegroundColor Cyan
    try {
        Invoke-WebRequest -Uri $Url -OutFile $OutputPath -UseBasicParsing
        Write-Host "Downloaded: $Description" -ForegroundColor Green
    }
    catch {
        Write-Error "Failed to download $Description from $Url"
        throw
    }
}

# Extract ZIP function
function Extract-Archive {
    param(
        [string]$ArchivePath,
        [string]$DestinationPath,
        [string]$Description
    )
    
    Write-Host "Extracting $Description..." -ForegroundColor Cyan
    try {
        Expand-Archive -Path $ArchivePath -DestinationPath $DestinationPath -Force
        Write-Host "Extracted: $Description" -ForegroundColor Green
    }
    catch {
        Write-Error "Failed to extract $Description"
        throw
    }
}

if (-not $SkipDownloads) {
    # Download PHP
    $PHPUrl = "https://windows.php.net/downloads/releases/php-$PHPVersion-Win32-vs16-x64.zip"
    $PHPZip = "$TempPath\php.zip"
    Download-File -Url $PHPUrl -OutputPath $PHPZip -Description "PHP $PHPVersion"
    
    # Download MySQL (MariaDB)
    $MySQLUrl = "https://archive.mariadb.org/mariadb-10.6.12/winx64-packages/mariadb-10.6.12-winx64.zip"
    $MySQLZip = "$TempPath\mysql.zip"
    Download-File -Url $MySQLUrl -OutputPath $MySQLZip -Description "MariaDB"
    
    # Download Nginx
    $NginxUrl = "http://nginx.org/download/nginx-1.22.1.zip"
    $NginxZip = "$TempPath\nginx.zip"
    Download-File -Url $NginxUrl -OutputPath $NginxZip -Description "Nginx"
    
    # Download WordPress
    $WordPressUrl = "https://wordpress.org/latest.zip"
    $WordPressZip = "$TempPath\wordpress.zip"
    Download-File -Url $WordPressUrl -OutputPath $WordPressZip -Description "WordPress"
    
    # Extract archives
    Extract-Archive -ArchivePath $PHPZip -DestinationPath $PHPPath -Description "PHP"
    Extract-Archive -ArchivePath $MySQLZip -DestinationPath $MySQLPath -Description "MariaDB"
    Extract-Archive -ArchivePath $NginxZip -DestinationPath $NginxPath -Description "Nginx"
    Extract-Archive -ArchivePath $WordPressZip -DestinationPath $TempPath -Description "WordPress"
    
    # Move WordPress files to correct location
    if (Test-Path "$TempPath\wordpress") {
        # Ensure destination exists
        New-Item -ItemType Directory -Force -Path $WordPressPath | Out-Null
        Move-Item "$TempPath\wordpress\*" $WordPressPath -Force
        Remove-Item "$TempPath\wordpress" -Recurse -Force
    }
    
    # Cleanup downloaded archives to save space
    Write-Host "Cleaning up downloaded archives..." -ForegroundColor Cyan
    Remove-Item $PHPZip -ErrorAction SilentlyContinue
    Remove-Item $MySQLZip -ErrorAction SilentlyContinue  
    Remove-Item $NginxZip -ErrorAction SilentlyContinue
    Remove-Item $WordPressZip -ErrorAction SilentlyContinue
    Write-Host "Download cleanup completed." -ForegroundColor Green
}

# Configure PHP
Write-Host "Configuring PHP..." -ForegroundColor Cyan
$PHPIniPath = "$PHPPath\php.ini"
$PHPIniDevPath = "$PHPPath\php.ini-development"

if (Test-Path $PHPIniDevPath -and -not (Test-Path $PHPIniPath)) {
    Copy-Item $PHPIniDevPath $PHPIniPath
}

# PHP configuration
$PHPConfig = @"
; WebP Migrator Test Environment PHP Configuration
extension_dir = "ext"
extension=gd
extension=mysqli
extension=pdo_mysql
extension=mbstring
extension=openssl
extension=curl
extension=fileinfo
extension=zip

; Memory and execution settings
memory_limit = 512M
max_execution_time = 300
max_input_time = 300
post_max_size = 100M
upload_max_filesize = 100M

; Error reporting
display_errors = On
display_startup_errors = On
error_reporting = E_ALL

; Timezone
date.timezone = UTC
"@

Add-Content -Path $PHPIniPath -Value $PHPConfig

# Configure MySQL
Write-Host "Configuring MariaDB..." -ForegroundColor Cyan
$MySQLConfigPath = "$MySQLPath\my.ini"
$MySQLConfig = @"
[mysqld]
port=3306
datadir=$($MySQLPath.Replace('\', '/') + '/data')
basedir=$($MySQLPath.Replace('\', '/'))
bind-address=127.0.0.1
default-storage-engine=innodb
innodb_buffer_pool_size=256M
max_allowed_packet=64M

[client]
port=3306
default-character-set=utf8mb4

[mysql]
default-character-set=utf8mb4
"@

Set-Content -Path $MySQLConfigPath -Value $MySQLConfig

# Initialize MySQL data directory
$MySQLBin = Get-ChildItem -Path $MySQLPath -Name "mariadb-*" -Directory | Select-Object -First 1
if ($MySQLBin) {
    $MySQLBinPath = "$MySQLPath\$MySQLBin\bin"
    Write-Host "Initializing MySQL data directory..." -ForegroundColor Cyan
    
    # Create data directory
    $MySQLDataPath = "$MySQLPath\data"
    New-Item -ItemType Directory -Force -Path $MySQLDataPath | Out-Null
    
    # Install MySQL service
    try {
        & "$MySQLBinPath\mysql_install_db.exe" --datadir="$MySQLDataPath" --service=WebPMigratorMySQL --password=root123
    }
    catch {
        Write-Warning "MySQL initialization may have failed, but continuing..."
    }
}

# Configure Nginx
Write-Host "Configuring Nginx..." -ForegroundColor Cyan
$NginxBin = Get-ChildItem -Path $NginxPath -Name "nginx-*" -Directory | Select-Object -First 1
if ($NginxBin) {
    $NginxBinPath = "$NginxPath\$NginxBin"
    $NginxConfigPath = "$NginxBinPath\conf\nginx.conf"
    
    $NginxConfig = @"
worker_processes 1;
error_log logs/error.log;
pid logs/nginx.pid;

events {
    worker_connections 1024;
}

http {
    include mime.types;
    default_type application/octet-stream;
    sendfile on;
    keepalive_timeout 65;
    
    server {
        listen 8080;
        server_name localhost;
        root $($WordPressPath.Replace('\', '/'));
        index index.php index.html index.htm;
        
        location / {
            try_files `$uri `$uri/ /index.php?`$args;
        }
        
        location ~ \.php$ {
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME `$document_root`$fastcgi_script_name;
            include fastcgi_params;
        }
        
        location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|webp)$ {
            expires 1y;
            add_header Cache-Control "public, immutable";
        }
    }
}
"@

    Set-Content -Path $NginxConfigPath -Value $NginxConfig
}

# Configure WordPress
Write-Host "Configuring WordPress..." -ForegroundColor Cyan
$WPConfigPath = "$WordPressPath\wp-config.php"
$WPConfigSamplePath = "$WordPressPath\wp-config-sample.php"

if (Test-Path $WPConfigSamplePath -and -not (Test-Path $WPConfigPath)) {
    $WPConfig = Get-Content $WPConfigSamplePath -Raw
    
    # Replace database settings
    $WPConfig = $WPConfig -replace "database_name_here", "wordpress_webp_test"
    $WPConfig = $WPConfig -replace "username_here", "root"
    $WPConfig = $WPConfig -replace "password_here", "root123"
    $WPConfig = $WPConfig -replace "localhost", "127.0.0.1"
    
    # Add debug settings
    $WPConfig = $WPConfig -replace "\/\*.*stop editing.*\*\/", @"
/* Debug settings for development */
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('SCRIPT_DEBUG', true);

/* Increase memory limit */
define('WP_MEMORY_LIMIT', '512M');

/* Allow direct file modifications */
define('FS_METHOD', 'direct');

/* That's all, stop editing! Happy publishing. */
"@
    
    Set-Content -Path $WPConfigPath -Value $WPConfig -Encoding UTF8
}

# Create batch files for easy service management
Write-Host "Creating service management scripts..." -ForegroundColor Cyan

# Start services script
$StartScript = @"
@echo off
echo Starting WebP Migrator Test Environment...

echo Starting MySQL...
net start WebPMigratorMySQL

echo Starting PHP-FPM...
start "PHP-FPM" "$PHPPath\php-cgi.exe" -b 127.0.0.1:9000

echo Starting Nginx...
cd /d "$NginxBinPath"
start "Nginx" nginx.exe

echo.
echo Services started!
echo WordPress is available at: http://localhost:8080
echo.
pause
"@

Set-Content -Path "$BasePath\start-services.bat" -Value $StartScript

# Stop services script
$StopScript = @"
@echo off
echo Stopping WebP Migrator Test Environment...

echo Stopping Nginx...
taskkill /f /im nginx.exe 2>nul

echo Stopping PHP-FPM...
taskkill /f /im php-cgi.exe 2>nul

echo Stopping MySQL...
net stop WebPMigratorMySQL

echo.
echo Services stopped!
echo.
pause
"@

Set-Content -Path "$BasePath\stop-services.bat" -Value $StopScript

# Create database setup script
$DBSetupScript = @"
@echo off
echo Setting up WordPress database...

"$MySQLBinPath\mysql.exe" -u root -proot123 -e "CREATE DATABASE IF NOT EXISTS wordpress_webp_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"$MySQLBinPath\mysql.exe" -u root -proot123 -e "GRANT ALL PRIVILEGES ON wordpress_webp_test.* TO 'root'@'localhost';"
"$MySQLBinPath\mysql.exe" -u root -proot123 -e "FLUSH PRIVILEGES;"

echo Database setup complete!
pause
"@

Set-Content -Path "$BasePath\setup-database.bat" -Value $DBSetupScript

# Create plugin installation script
$PluginInstallScript = @"
@echo off
echo Installing WebP Safe Migrator Plugin...

set PLUGIN_DIR=$WordPressPath\wp-content\plugins\webp-safe-migrator
set SOURCE_DIR=%~dp0..\src

if exist "%PLUGIN_DIR%" (
    echo Removing existing plugin...
    rmdir /s /q "%PLUGIN_DIR%"
)

echo Creating plugin directory...
mkdir "%PLUGIN_DIR%"

echo Copying plugin files...
xcopy "%SOURCE_DIR%\*" "%PLUGIN_DIR%\" /e /i /y

echo Plugin installed successfully!
echo You can now activate it in WordPress admin.
pause
"@

Set-Content -Path "$BasePath\install-plugin.bat" -Value $PluginInstallScript

# Create README
$ReadmeContent = @"
# WebP Safe Migrator Test Environment

This is a local WordPress development environment for testing the WebP Safe Migrator plugin.

## Quick Start

1. Run 'setup-database.bat' to create the WordPress database
2. Run 'start-services.bat' to start all services
3. Open http://localhost:8080 in your browser
4. Complete WordPress installation
5. Run 'install-plugin.bat' to install the WebP Safe Migrator plugin
6. Activate the plugin in WordPress admin

## Service Management

- **Start Services**: Double-click 'start-services.bat'
- **Stop Services**: Double-click 'stop-services.bat'
- **Install Plugin**: Double-click 'install-plugin.bat'

## Access Information

- **WordPress URL**: http://localhost:8080
- **MySQL Host**: 127.0.0.1:3306
- **MySQL Database**: wordpress_webp_test
- **MySQL Username**: root
- **MySQL Password**: root123

## Directory Structure

- PHP: $PHPPath
- MySQL: $MySQLPath
- Nginx: $NginxPath
- WordPress: $WordPressPath

## Troubleshooting

1. If services don't start, check Windows Defender/antivirus
2. Ensure ports 8080, 3306, and 9000 are not in use
3. Run Command Prompt as Administrator if needed
4. Check error logs in respective service directories

## Plugin Development

The plugin source is in the 'src' directory. After making changes:
1. Run 'install-plugin.bat' to update the plugin
2. Refresh WordPress admin to see changes

"@

Set-Content -Path "$BasePath\README.txt" -Value $ReadmeContent

if ($StartServices) {
    Write-Host "Starting services..." -ForegroundColor Cyan
    & "$BasePath\start-services.bat"
}

Write-Host ""
Write-Host "=== Setup Complete! ===" -ForegroundColor Green
Write-Host "Installation path: $BasePath" -ForegroundColor Yellow
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Cyan
Write-Host "1. Run 'setup-database.bat' to create the database" -ForegroundColor White
Write-Host "2. Run 'start-services.bat' to start services" -ForegroundColor White
Write-Host "3. Open http://localhost:8080 to set up WordPress" -ForegroundColor White
Write-Host "4. Run 'install-plugin.bat' to install WebP Safe Migrator" -ForegroundColor White
Write-Host ""
Write-Host "See README.txt for detailed instructions." -ForegroundColor Yellow
