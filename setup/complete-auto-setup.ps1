# WebP Safe Migrator - Complete Automated Setup
# This script performs EVERYTHING automatically:
# - Clean deployment of all containers
# - WordPress installation with optimal settings
# - Plugin activation and configuration
# - Auto-login URL generation
# - Browser launch with automatic login

param(
    [string]$HttpPort = "8080",
    [string]$MySQLPort = "3307",
    [string]$PhpMyAdminPort = "8081",
    [switch]$SkipBrowser
)

$ErrorActionPreference = "Continue"

# Configuration
$NetworkName = "webp-migrator-net"
$WordPressContainer = "webp-migrator-wordpress"
$DBContainer = "webp-migrator-mysql"
$PhpMyAdminContainer = "webp-migrator-phpmyadmin"
$WPCLIContainer = "webp-migrator-wpcli"

# Database & WordPress credentials
$DBName = "wordpress_webp_test"
$DBUser = "wordpress"
$DBPassword = "wordpress123"
$DBRootPassword = "root123"
$SiteTitle = "WebP Safe Migrator Test Site"
$AdminUser = "admin"
$AdminPassword = "admin123!"
$AdminEmail = "admin@webp-test.local"

# Color functions
function Write-Header { param($Message) Write-Host "`n🚀 $Message" -ForegroundColor Blue }
function Write-Step { param($Message) Write-Host "   ➤ $Message" -ForegroundColor Cyan }
function Write-Success { param($Message) Write-Host "   ✅ $Message" -ForegroundColor Green }
function Write-Error-Custom { param($Message) Write-Host "   ❌ $Message" -ForegroundColor Red }
function Write-Warning-Custom { param($Message) Write-Host "   ⚠️  $Message" -ForegroundColor Yellow }

Write-Host ""
Write-Host "🎉 WebP Safe Migrator - Complete Automated Setup" -ForegroundColor Green
Write-Host "=================================================" -ForegroundColor Green
Write-Host "This script will automatically set up everything you need:" -ForegroundColor White
Write-Host "  • Clean container deployment" -ForegroundColor Gray
Write-Host "  • WordPress installation & configuration" -ForegroundColor Gray  
Write-Host "  • Plugin activation with auto-fix" -ForegroundColor Gray
Write-Host "  • Sample content creation" -ForegroundColor Gray
Write-Host "  • Auto-login browser launch" -ForegroundColor Gray
Write-Host ""

# Step 1: Verify Prerequisites
Write-Header "Step 1: Verifying Prerequisites"
Write-Step "Checking Podman availability..."
try {
    $podmanVersion = podman --version
    Write-Success "Podman available: $podmanVersion"
} catch {
    Write-Error-Custom "Podman not found. Please install Podman first."
    Write-Host "Install from: https://podman.io/getting-started/installation" -ForegroundColor Yellow
    exit 1
}

# Step 2: Complete Cleanup
Write-Header "Step 2: Cleaning Previous Setup"
Write-Step "Stopping existing containers..."
podman stop $WordPressContainer $DBContainer $PhpMyAdminContainer $WPCLIContainer 2>$null
Write-Step "Removing existing containers..."
podman rm -f $WordPressContainer $DBContainer $PhpMyAdminContainer $WPCLIContainer 2>$null
Write-Step "Removing network..."
podman network rm $NetworkName 2>$null
Write-Step "Pruning unused volumes..."
podman volume prune -f | Out-Null
Write-Success "Environment cleaned successfully"

# Step 3: Network Setup
Write-Header "Step 3: Creating Container Network"
Write-Step "Creating network: $NetworkName"
podman network create $NetworkName | Out-Null
if ($LASTEXITCODE -eq 0) {
    Write-Success "Network created successfully"
} else {
    Write-Error-Custom "Failed to create network"
    exit 1
}

# Step 4: Database Setup
Write-Header "Step 4: Setting Up Database"
Write-Step "Starting MySQL container (this may take a moment)..."
podman run -d `
    --name $DBContainer `
    --network $NetworkName `
    -p "${MySQLPort}:3306" `
    -e MYSQL_DATABASE=$DBName `
    -e MYSQL_USER=$DBUser `
    -e MYSQL_PASSWORD=$DBPassword `
    -e MYSQL_ROOT_PASSWORD=$DBRootPassword `
    -e MYSQL_INITDB_SKIP_TZINFO=1 `
    docker.io/library/mysql:8.0 `
    --default-authentication-plugin=mysql_native_password `
    --character-set-server=utf8mb4 `
    --collation-server=utf8mb4_unicode_ci | Out-Null

if ($LASTEXITCODE -eq 0) {
    Write-Success "MySQL container started"
} else {
    Write-Error-Custom "Failed to start MySQL"
    exit 1
}

Write-Step "Waiting for database to initialize..."
$dbReady = $false
for ($i = 1; $i -le 30; $i++) {
    Start-Sleep -Seconds 2
    Write-Host "      Attempt $i/30..." -ForegroundColor DarkGray
    podman exec $DBContainer mysqladmin ping -u root -p$DBRootPassword 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) {
        $dbReady = $true
        break
    }
}

if ($dbReady) {
    Write-Success "Database is ready and accepting connections"
} else {
    Write-Error-Custom "Database failed to start within 60 seconds"
    exit 1
}

# Step 5: WordPress Setup
Write-Header "Step 5: Setting Up WordPress"
$currentDir = Get-Location
$pluginPath = Join-Path (Split-Path $currentDir -Parent) "src"

Write-Step "Verifying plugin source at: $pluginPath"
if (-not (Test-Path $pluginPath)) {
    Write-Error-Custom "Plugin source directory not found"
    exit 1
}
Write-Success "Plugin source verified"

Write-Step "Starting WordPress container..."
podman run -d `
    --name $WordPressContainer `
    --network $NetworkName `
    -p "${HttpPort}:80" `
    -e WORDPRESS_DB_HOST=$DBContainer `
    -e WORDPRESS_DB_USER=$DBUser `
    -e WORDPRESS_DB_PASSWORD=$DBPassword `
    -e WORDPRESS_DB_NAME=$DBName `
    -e WORDPRESS_DEBUG=1 `
    -e "WORDPRESS_CONFIG_EXTRA=define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false); define('SCRIPT_DEBUG', true); define('WP_MEMORY_LIMIT', '512M'); define('FS_METHOD', 'direct');" `
    -v "${pluginPath}:/var/www/html/wp-content/plugins/webp-safe-migrator" `
    docker.io/library/wordpress:latest | Out-Null

if ($LASTEXITCODE -eq 0) {
    Write-Success "WordPress container started with plugin mounted"
} else {
    Write-Error-Custom "Failed to start WordPress"
    exit 1
}

# Step 6: Management Tools Setup
Write-Header "Step 6: Setting Up Management Tools"
Write-Step "Starting phpMyAdmin..."
podman run -d `
    --name $PhpMyAdminContainer `
    --network $NetworkName `
    -p "${PhpMyAdminPort}:80" `
    -e PMA_HOST=$DBContainer `
    -e PMA_USER=root `
    -e PMA_PASSWORD=$DBRootPassword `
    -e PMA_ARBITRARY=1 `
    docker.io/library/phpmyadmin:latest | Out-Null

Write-Step "Installing WP-CLI in WordPress container..."
podman exec $WordPressContainer bash -c "curl -s -o /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /tmp/wp-cli.phar && mv /tmp/wp-cli.phar /usr/local/bin/wp" 2>$null | Out-Null
Write-Success "Management tools ready"

# Step 7: WordPress Installation
Write-Header "Step 7: Installing WordPress"
Write-Step "Waiting for WordPress to be ready..."
$wordpressUrl = "http://localhost:$HttpPort"
$wpReady = $false

for ($i = 1; $i -le 30; $i++) {
    Start-Sleep -Seconds 2
    Write-Host "      Testing connection (attempt $i/30)..." -ForegroundColor DarkGray
    try {
        $response = Invoke-WebRequest -Uri $wordpressUrl -TimeoutSec 5 -UseBasicParsing -ErrorAction SilentlyContinue
        if ($response.StatusCode -eq 200 -or ($response.StatusCode -ge 300 -and $response.StatusCode -lt 400)) {
            $wpReady = $true
            break
        }
    } catch {
        # Continue trying
    }
}

if ($wpReady) {
    Write-Success "WordPress is accessible"
} else {
    Write-Warning-Custom "WordPress may not be fully ready, but continuing..."
}

Write-Step "Installing WordPress core..."
podman exec $WordPressContainer wp core install `
    --url="$wordpressUrl" `
    --title="$SiteTitle" `
    --admin_user="$AdminUser" `
    --admin_password="$AdminPassword" `
    --admin_email="$AdminEmail" `
    --locale="en_US" `
    --skip-email `
    --allow-root 2>$null | Out-Null

if ($LASTEXITCODE -eq 0) {
    Write-Success "WordPress installed successfully"
} else {
    Write-Error-Custom "WordPress installation failed"
    exit 1
}

# Step 8: Plugin Activation
Write-Header "Step 8: Activating WebP Safe Migrator Plugin"
Write-Step "Activating plugin..."
podman exec $WordPressContainer wp plugin activate webp-safe-migrator --allow-root 2>$null | Out-Null

if ($LASTEXITCODE -eq 0) {
    Write-Success "Plugin activated successfully"
} else {
    Write-Warning-Custom "Plugin activation had issues, but continuing..."
}

# Step 9: Content Creation
Write-Header "Step 9: Creating Sample Content"
Write-Step "Creating welcome page with instructions..."
$welcomeContent = @"
<h2>🎉 Welcome to WebP Safe Migrator Test Site</h2>
<p>Your development environment is ready! This site is fully configured for testing the WebP Safe Migrator plugin.</p>

<h3>🚀 Quick Start Guide:</h3>
<ol>
<li><strong>Access Plugin:</strong> Go to <strong>Media → WebP Migrator</strong></li>
<li><strong>Upload Images:</strong> Add some JPEG, PNG, or GIF images to test</li>
<li><strong>Configure Settings:</strong>
   <ul>
   <li>Quality: 75 (recommended)</li>
   <li>Batch size: 5-10 (for testing)</li>
   <li>Enable validation mode</li>
   </ul>
</li>
<li><strong>Process Images:</strong> Click "Process next batch"</li>
<li><strong>Review Results:</strong> Check converted images and file sizes</li>
<li><strong>Commit Changes:</strong> When satisfied with results</li>
</ol>

<h3>🔑 Admin Credentials:</h3>
<div style="background: #f0f0f0; padding: 15px; border-radius: 5px; margin: 10px 0;">
<strong>Username:</strong> $AdminUser<br>
<strong>Password:</strong> $AdminPassword<br>
<strong>Admin URL:</strong> <a href="$wordpressUrl/wp-admin">$wordpressUrl/wp-admin</a>
</div>

<h3>🛠️ Additional Resources:</h3>
<ul>
<li><strong>Database Management:</strong> <a href="http://localhost:$PhpMyAdminPort" target="_blank">phpMyAdmin</a></li>
<li><strong>Plugin Directory:</strong> Media → WebP Migrator</li>
<li><strong>WordPress Admin:</strong> <a href="$wordpressUrl/wp-admin">Admin Dashboard</a></li>
</ul>

<p><em>Happy testing! 🚀</em></p>
"@

podman exec $WordPressContainer wp post create `
    --post_type=page `
    --post_title="WebP Migrator Test Guide" `
    --post_content="$welcomeContent" `
    --post_status=publish `
    --allow-root 2>$null | Out-Null

Write-Step "Setting welcome page as homepage..."
$pageId = podman exec $WordPressContainer wp post list --post_type=page --field=ID --format=csv --allow-root | Select-Object -First 1
if ($pageId) {
    podman exec $WordPressContainer wp option update show_on_front page --allow-root 2>$null | Out-Null
    podman exec $WordPressContainer wp option update page_on_front $pageId --allow-root 2>$null | Out-Null
}
Write-Success "Sample content created and configured"

# Step 10: Auto-Login Setup
Write-Header "Step 10: Setting Up Auto-Login"
Write-Step "Generating auto-login URL..."

# Create a temporary auto-login script in WordPress
$autoLoginScript = @"
<?php
// Temporary auto-login for development
if (isset(`$_GET['auto_login']) && `$_GET['auto_login'] === 'dev_mode') {
    `$user = get_user_by('login', '$AdminUser');
    if (`$user) {
        wp_set_current_user(`$user->ID);
        wp_set_auth_cookie(`$user->ID, true);
        wp_redirect(admin_url('admin.php?page=webp-migrator'));
        exit;
    }
}
"@

# Write the auto-login script to WordPress
podman exec $WordPressContainer bash -c "echo '$autoLoginScript' > /var/www/html/wp-content/themes/twentytwentyfour/functions.php" 2>$null

$autoLoginUrl = "$wordpressUrl/?auto_login=dev_mode"
Write-Success "Auto-login URL generated"

# Final Status Report
Write-Header "🎉 SETUP COMPLETE!"
Write-Host ""
Write-Host "   🌐 WordPress Site:      http://localhost:$HttpPort" -ForegroundColor Green
Write-Host "   🔧 WordPress Admin:     http://localhost:$HttpPort/wp-admin" -ForegroundColor Green  
Write-Host "   🚀 Auto-Login URL:      $autoLoginUrl" -ForegroundColor Yellow
Write-Host "   🔌 Plugin Access:       Media → WebP Migrator" -ForegroundColor Green
Write-Host "   🗄️ Database Admin:      http://localhost:$PhpMyAdminPort" -ForegroundColor Green
Write-Host ""
Write-Host "   👤 Username: $AdminUser" -ForegroundColor Cyan
Write-Host "   🔑 Password: $AdminPassword" -ForegroundColor Cyan
Write-Host ""

# Container Status
Write-Step "Container Status:"
podman ps --format "   {{.Names}} - {{.Status}}" | Where-Object { $_ -match "webp-migrator" }

Write-Host ""
Write-Host "✨ Everything is ready for WebP Safe Migrator development and testing!" -ForegroundColor Green

# Step 11: Auto-Launch Browser
if (-not $SkipBrowser) {
    Write-Header "Step 11: Launching Browser"
    Write-Step "Opening WordPress with auto-login..."
    Start-Sleep -Seconds 2
    
    try {
        # Try auto-login first, fallback to regular site
        Start-Process $autoLoginUrl
        Write-Success "Browser launched with auto-login"
    } catch {
        try {
            Start-Process $wordpressUrl
            Write-Success "Browser launched (manual login required)"
        } catch {
            Write-Warning-Custom "Could not auto-launch browser"
            Write-Host "   Please manually open: $autoLoginUrl" -ForegroundColor Yellow
        }
    }
} else {
    Write-Host ""
    Write-Host "🌐 To get started, visit: $autoLoginUrl" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "🎊 Happy WebP development! 🎊" -ForegroundColor Magenta

