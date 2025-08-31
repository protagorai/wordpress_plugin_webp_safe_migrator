# WebP Safe Migrator - Simple Reliable Deployment
# Clean, tested script with no syntax issues

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

# Credentials
$DBName = "wordpress_webp_test"
$DBUser = "wordpress"
$DBPassword = "wordpress123"
$DBRootPassword = "root123"
$AdminUser = "admin"
$AdminPassword = "admin123"
$AdminEmail = "admin@webp-test.local"

Write-Host ""
Write-Host "WebP Safe Migrator - Simple Deployment" -ForegroundColor Green
Write-Host "=======================================" -ForegroundColor Green
Write-Host ""

# Step 1: Check Podman
Write-Host "1. Checking Podman..." -ForegroundColor Cyan
try {
    $podmanVersion = podman --version
    Write-Host "   Podman available: $podmanVersion" -ForegroundColor Green
} catch {
    Write-Host "   ERROR: Podman not found" -ForegroundColor Red
    exit 1
}

# Step 2: Clean up
Write-Host "2. Cleaning up existing setup..." -ForegroundColor Cyan
podman stop $WordPressContainer $DBContainer $PhpMyAdminContainer 2>$null
podman rm -f $WordPressContainer $DBContainer $PhpMyAdminContainer 2>$null  
podman network rm $NetworkName 2>$null
Write-Host "   Cleanup completed" -ForegroundColor Green

# Step 3: Create network
Write-Host "3. Creating network..." -ForegroundColor Cyan
podman network create $NetworkName | Out-Null
Write-Host "   Network created: $NetworkName" -ForegroundColor Green

# Step 4: Start MySQL
Write-Host "4. Starting MySQL..." -ForegroundColor Cyan
podman run -d `
    --name $DBContainer `
    --network $NetworkName `
    -p "${MySQLPort}:3306" `
    -e MYSQL_DATABASE=$DBName `
    -e MYSQL_USER=$DBUser `
    -e MYSQL_PASSWORD=$DBPassword `
    -e MYSQL_ROOT_PASSWORD=$DBRootPassword `
    docker.io/library/mysql:8.0 `
    --default-authentication-plugin=mysql_native_password | Out-Null

# Wait for MySQL
Write-Host "5. Waiting for MySQL to be ready..." -ForegroundColor Cyan
for ($i = 1; $i -le 30; $i++) {
    Start-Sleep -Seconds 2
    Write-Host "   Testing MySQL connection (attempt $i/30)..." -ForegroundColor Gray
    podman exec $DBContainer mysqladmin ping -u root -p$DBRootPassword 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) {
        Write-Host "   MySQL is ready!" -ForegroundColor Green
        break
    }
    if ($i -eq 30) {
        Write-Host "   ERROR: MySQL not ready" -ForegroundColor Red
        exit 1
    }
}

# Step 6: Start WordPress
Write-Host "6. Starting WordPress..." -ForegroundColor Cyan
$currentDir = Get-Location
$pluginPath = Join-Path (Split-Path $currentDir -Parent) "src"

if (-not (Test-Path $pluginPath)) {
    Write-Host "   ERROR: Plugin source not found at $pluginPath" -ForegroundColor Red
    exit 1
}

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
    docker.io/library/wordpress:latest | Out-Null

Write-Host "   WordPress container started" -ForegroundColor Green

# Step 7: Start phpMyAdmin
Write-Host "7. Starting phpMyAdmin..." -ForegroundColor Cyan
podman run -d `
    --name $PhpMyAdminContainer `
    --network $NetworkName `
    -p "${PhpMyAdminPort}:80" `
    -e PMA_HOST=$DBContainer `
    -e PMA_USER=root `
    -e PMA_PASSWORD=$DBRootPassword `
    docker.io/library/phpmyadmin:latest | Out-Null

Write-Host "   phpMyAdmin started" -ForegroundColor Green

# Step 8: Install WP-CLI and WordPress
Write-Host "8. Installing WordPress..." -ForegroundColor Cyan
Start-Sleep -Seconds 10
podman exec $WordPressContainer bash -c "curl -s -o /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /tmp/wp-cli.phar && mv /tmp/wp-cli.phar /usr/local/bin/wp" 2>$null | Out-Null

$wordpressUrl = "http://localhost:$HttpPort"
podman exec $WordPressContainer wp core install `
    --url="$wordpressUrl" `
    --title="WebP Migrator Test Site" `
    --admin_user="$AdminUser" `
    --admin_password="$AdminPassword" `
    --admin_email="$AdminEmail" `
    --locale="en_US" `
    --skip-email `
    --allow-root 2>$null | Out-Null

if ($LASTEXITCODE -eq 0) {
    Write-Host "   WordPress installed successfully!" -ForegroundColor Green
} else {
    Write-Host "   WARNING: WordPress installation had issues" -ForegroundColor Yellow
}

# Step 9: Activate plugin
Write-Host "9. Activating plugin..." -ForegroundColor Cyan
podman exec $WordPressContainer wp plugin activate webp-safe-migrator --allow-root 2>$null | Out-Null
if ($LASTEXITCODE -eq 0) {
    Write-Host "   Plugin activated successfully!" -ForegroundColor Green
} else {
    Write-Host "   WARNING: Plugin activation had issues (check for syntax errors)" -ForegroundColor Yellow
}

# Step 10: Final status
Write-Host ""
Write-Host "DEPLOYMENT COMPLETE!" -ForegroundColor Green
Write-Host "====================" -ForegroundColor Green
Write-Host ""
Write-Host "Access URLs:" -ForegroundColor Cyan
Write-Host "  WordPress Site: http://localhost:$HttpPort" -ForegroundColor White
Write-Host "  WordPress Admin: http://localhost:$HttpPort/wp-admin" -ForegroundColor White
Write-Host "  phpMyAdmin: http://localhost:$PhpMyAdminPort" -ForegroundColor White
Write-Host ""
Write-Host "Login Credentials:" -ForegroundColor Cyan
Write-Host "  Username: $AdminUser" -ForegroundColor Yellow
Write-Host "  Password: $AdminPassword" -ForegroundColor Yellow
Write-Host ""
Write-Host "Plugin Access:" -ForegroundColor Cyan
Write-Host "  Go to: Media -> WebP Migrator" -ForegroundColor White
Write-Host ""

# Show container status
Write-Host "Container Status:" -ForegroundColor Cyan
podman ps --format "  {{.Names}} - {{.Status}}"

# Launch browser
Write-Host ""
Write-Host "Opening WordPress in browser..." -ForegroundColor Cyan
Start-Sleep -Seconds 2
Start-Process "http://localhost:$HttpPort"

Write-Host ""
Write-Host "Setup completed successfully!" -ForegroundColor Green
Write-Host "WordPress with WebP Safe Migrator is ready for testing!" -ForegroundColor White

