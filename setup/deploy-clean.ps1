# WebP Safe Migrator - Simple Clean Deployment
# Fixed version with no smart quotes or parsing issues

param(
    [string]$HttpPort = "8080",
    [string]$MySQLPort = "3307", 
    [string]$PhpMyAdminPort = "8081"
)

$ErrorActionPreference = "Continue"

# Configuration
$NetworkName = "webp-migrator-net"
$WordPressContainer = "webp-migrator-wordpress"
$DBContainer = "webp-migrator-mysql"
$PhpMyAdminContainer = "webp-migrator-phpmyadmin"
$WPCLIContainer = "webp-migrator-wpcli"

# Database config
$DBName = "wordpress_webp_test"
$DBUser = "wordpress"
$DBPassword = "wordpress123"
$DBRootPassword = "root123"

# WordPress config
$SiteTitle = "WebP Migrator Test Site"
$AdminUser = "admin"
$AdminPassword = "admin123!"
$AdminEmail = "admin@webp-test.local"

Write-Host ""
Write-Host "=== WebP Safe Migrator - Clean Deployment ===" -ForegroundColor Green
Write-Host ""

# Test Podman
Write-Host "Checking Podman..." -ForegroundColor Cyan
try {
    $null = podman --version
    Write-Host "Podman is available" -ForegroundColor Green
} catch {
    Write-Host "ERROR: Podman not found" -ForegroundColor Red
    exit 1
}

# Clean up existing containers
Write-Host "Cleaning up existing containers..." -ForegroundColor Cyan
podman stop $WordPressContainer $DBContainer $PhpMyAdminContainer $WPCLIContainer 2>$null
podman rm -f $WordPressContainer $DBContainer $PhpMyAdminContainer $WPCLIContainer 2>$null
podman network rm $NetworkName 2>$null
Write-Host "Cleanup completed" -ForegroundColor Green

# Create network
Write-Host "Creating network..." -ForegroundColor Cyan
podman network create $NetworkName
if ($LASTEXITCODE -eq 0) {
    Write-Host "Network created: $NetworkName" -ForegroundColor Green
} else {
    Write-Host "ERROR: Failed to create network" -ForegroundColor Red
    exit 1
}

# Start MySQL
Write-Host "Starting MySQL container..." -ForegroundColor Cyan
podman run -d `
    --name $DBContainer `
    --network $NetworkName `
    -p "${MySQLPort}:3306" `
    -e MYSQL_DATABASE=$DBName `
    -e MYSQL_USER=$DBUser `
    -e MYSQL_PASSWORD=$DBPassword `
    -e MYSQL_ROOT_PASSWORD=$DBRootPassword `
    docker.io/library/mysql:8.0 `
    --default-authentication-plugin=mysql_native_password

if ($LASTEXITCODE -eq 0) {
    Write-Host "MySQL container started" -ForegroundColor Green
} else {
    Write-Host "ERROR: Failed to start MySQL" -ForegroundColor Red
    exit 1
}

# Wait for MySQL
Write-Host "Waiting for MySQL to be ready..." -ForegroundColor Cyan
for ($i = 1; $i -le 30; $i++) {
    Start-Sleep -Seconds 2
    Write-Host "  Checking MySQL (attempt $i/30)..." -ForegroundColor Yellow
    
    podman exec $DBContainer mysqladmin ping -u root -p$DBRootPassword 2>$null
    if ($LASTEXITCODE -eq 0) {
        Write-Host "MySQL is ready!" -ForegroundColor Green
        break
    }
    
    if ($i -eq 30) {
        Write-Host "ERROR: MySQL not ready after 60 seconds" -ForegroundColor Red
        exit 1
    }
}

# Get plugin path
$currentDir = Get-Location
$pluginPath = Join-Path (Split-Path $currentDir -Parent) "src"
Write-Host "Plugin source path: $pluginPath" -ForegroundColor Yellow

if (-not (Test-Path $pluginPath)) {
    Write-Host "ERROR: Plugin source not found at $pluginPath" -ForegroundColor Red
    exit 1
}

# Start WordPress
Write-Host "Starting WordPress container..." -ForegroundColor Cyan
podman run -d `
    --name $WordPressContainer `
    --network $NetworkName `
    -p "${HttpPort}:80" `
    -e WORDPRESS_DB_HOST=$DBContainer `
    -e WORDPRESS_DB_USER=$DBUser `
    -e WORDPRESS_DB_PASSWORD=$DBPassword `
    -e WORDPRESS_DB_NAME=$DBName `
    -e WORDPRESS_DEBUG=1 `
    -v "${pluginPath}:/var/www/html/wp-content/plugins/webp-safe-migrator" `
    docker.io/library/wordpress:latest

if ($LASTEXITCODE -eq 0) {
    Write-Host "WordPress container started" -ForegroundColor Green
} else {
    Write-Host "ERROR: Failed to start WordPress" -ForegroundColor Red
    exit 1
}

# Start phpMyAdmin
Write-Host "Starting phpMyAdmin container..." -ForegroundColor Cyan
podman run -d `
    --name $PhpMyAdminContainer `
    --network $NetworkName `
    -p "${PhpMyAdminPort}:80" `
    -e PMA_HOST=$DBContainer `
    -e PMA_USER=root `
    -e PMA_PASSWORD=$DBRootPassword `
    docker.io/library/phpmyadmin:latest

if ($LASTEXITCODE -eq 0) {
    Write-Host "phpMyAdmin container started" -ForegroundColor Green
} else {
    Write-Host "ERROR: Failed to start phpMyAdmin" -ForegroundColor Red
    exit 1
}

# Start WP-CLI
Write-Host "Starting WP-CLI container..." -ForegroundColor Cyan
podman run -d `
    --name $WPCLIContainer `
    --network $NetworkName `
    -e WORDPRESS_DB_HOST=$DBContainer `
    -e WORDPRESS_DB_USER=$DBUser `
    -e WORDPRESS_DB_PASSWORD=$DBPassword `
    -e WORDPRESS_DB_NAME=$DBName `
    -v "wordpress_data:/var/www/html" `
    -v "${pluginPath}:/var/www/html/wp-content/plugins/webp-safe-migrator" `
    --entrypoint "" `
    docker.io/library/wordpress:cli `
    tail -f /dev/null

if ($LASTEXITCODE -eq 0) {
    Write-Host "WP-CLI container started" -ForegroundColor Green
} else {
    Write-Host "ERROR: Failed to start WP-CLI" -ForegroundColor Red
    exit 1
}

# Wait for WordPress
Write-Host "Waiting for WordPress to be ready..." -ForegroundColor Cyan
$wordpressUrl = "http://localhost:$HttpPort"

for ($i = 1; $i -le 30; $i++) {
    Start-Sleep -Seconds 2
    Write-Host "  Checking WordPress (attempt $i/30)..." -ForegroundColor Yellow
    
    try {
        $response = Invoke-WebRequest -Uri $wordpressUrl -TimeoutSec 5 -UseBasicParsing -ErrorAction SilentlyContinue
        $ready = ($response.StatusCode -eq 200) -or ($response.StatusCode -ge 300 -and $response.StatusCode -lt 400)
        if ($ready) {
            Write-Host "WordPress is ready!" -ForegroundColor Green
            break
        }
    } catch {
        # Continue trying
    }
    
    if ($i -eq 30) {
        Write-Host "WordPress may not be fully ready, but continuing..." -ForegroundColor Yellow
    }
}

# Install WordPress
Write-Host "Installing WordPress..." -ForegroundColor Cyan
podman exec $WPCLIContainer wp core install `
    --url="$wordpressUrl" `
    --title="$SiteTitle" `
    --admin_user="$AdminUser" `
    --admin_password="$AdminPassword" `
    --admin_email="$AdminEmail" `
    --locale="en_US" `
    --skip-email `
    --allow-root

if ($LASTEXITCODE -eq 0) {
    Write-Host "WordPress installed successfully!" -ForegroundColor Green
} else {
    Write-Host "ERROR: WordPress installation failed" -ForegroundColor Red
    exit 1
}

# Activate plugin
Write-Host "Activating WebP Safe Migrator plugin..." -ForegroundColor Cyan
podman exec $WPCLIContainer wp plugin activate webp-safe-migrator --allow-root

if ($LASTEXITCODE -eq 0) {
    Write-Host "Plugin activated successfully!" -ForegroundColor Green
} else {
    Write-Host "ERROR: Plugin activation failed" -ForegroundColor Red
    exit 1
}

# Create sample content
Write-Host "Creating sample content..." -ForegroundColor Cyan
podman exec $WPCLIContainer wp post create `
    --post_type=page `
    --post_title="WebP Migrator Test Guide" `
    --post_content="<h2>Welcome to WebP Safe Migrator Test Site</h2><p>Go to Media → WebP Migrator to start testing.</p>" `
    --post_status=publish `
    --allow-root 2>$null

# Show results
Write-Host ""
Write-Host "=== DEPLOYMENT COMPLETE! ===" -ForegroundColor Green
Write-Host ""
Write-Host "Access URLs:" -ForegroundColor Cyan
Write-Host "  WordPress Site: http://localhost:$HttpPort" -ForegroundColor White
Write-Host "  WordPress Admin: http://localhost:$HttpPort/wp-admin" -ForegroundColor White
Write-Host "  phpMyAdmin: http://localhost:$PhpMyAdminPort" -ForegroundColor White
Write-Host ""
Write-Host "WordPress Credentials:" -ForegroundColor Cyan
Write-Host "  Username: $AdminUser" -ForegroundColor Yellow
Write-Host "  Password: $AdminPassword" -ForegroundColor Yellow
Write-Host ""
Write-Host "Database Credentials:" -ForegroundColor Cyan
Write-Host "  Database: $DBName" -ForegroundColor White
Write-Host "  User: $DBUser / $DBPassword" -ForegroundColor Yellow
Write-Host "  Root: root / $DBRootPassword" -ForegroundColor Yellow
Write-Host ""
Write-Host "Plugin Access:" -ForegroundColor Cyan
Write-Host "  Go to Media → WebP Migrator in WordPress admin" -ForegroundColor White
Write-Host ""

# Show container status
Write-Host "Container Status:" -ForegroundColor Cyan
podman ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

Write-Host ""
Write-Host "Opening WordPress in browser..." -ForegroundColor Cyan
Start-Sleep -Seconds 2
Start-Process "http://localhost:$HttpPort"

Write-Host ""
Write-Host "SUCCESS! WordPress with WebP Safe Migrator is ready!" -ForegroundColor Green
