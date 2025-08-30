# WebP Safe Migrator - Automated Deployment Script
# Handles all container setup, WordPress installation, and plugin configuration automatically
# Fixes all issues encountered during manual setup

param(
    [string]$InstallPath = "$env:USERPROFILE\webp-migrator-test",
    [switch]$CleanStart,
    [switch]$SkipBrowser,
    [int]$HttpPort = 8080,
    [int]$HttpsPort = 8443,
    [int]$MySQLPort = 3306,
    [int]$PhpMyAdminPort = 8081
)

# Configuration
$ErrorActionPreference = "Continue"  # Continue on errors to handle cleanup
$NetworkName = "webp-migrator-net"
$WordPressContainer = "webp-migrator-wordpress"
$DBContainer = "webp-migrator-mysql" 
$PhpMyAdminContainer = "webp-migrator-phpmyadmin"
$WPCLIContainer = "webp-migrator-wpcli"

# Database Configuration
$DBName = "wordpress_webp_test"
$DBUser = "wordpress"
$DBPassword = "wordpress123"
$DBRootPassword = "root123"

# WordPress Configuration  
$SiteTitle = "WebP Migrator Test Site"
$AdminUser = "admin"
$AdminPassword = "admin123"
$AdminEmail = "admin@webp-test.local"

# Colors for output
function Write-Info { param($Message) Write-Host "[INFO] $Message" -ForegroundColor Cyan }
function Write-Success { param($Message) Write-Host "[SUCCESS] $Message" -ForegroundColor Green }
function Write-Warning { param($Message) Write-Host "[WARNING] $Message" -ForegroundColor Yellow }
function Write-Error-Custom { param($Message) Write-Host "[ERROR] $Message" -ForegroundColor Red }
function Write-Header { param($Message) Write-Host "`n=== $Message ===" -ForegroundColor Blue }

function Test-PodmanAvailable {
    Write-Info "Checking Podman availability..."
    try {
        $version = podman --version 2>$null
        if ($LASTEXITCODE -eq 0) {
            Write-Success "Podman is available: $version"
            return $true
        }
    }
    catch {
        # Ignore error, will return false
    }
    
    Write-Error-Custom "Podman not found. Please install Podman first."
    Write-Info "Install Podman Desktop from: https://podman.io/getting-started/installation"
    return $false
}

function Remove-ExistingContainers {
    Write-Header "Cleaning Up Existing Containers"
    
    $containers = @($WordPressContainer, $DBContainer, $PhpMyAdminContainer, $WPCLIContainer)
    
    foreach ($container in $containers) {
        Write-Info "Removing container: $container"
        podman rm -f $container 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) {
            Write-Success "Removed: $container"
        }
    }
    
    # Remove network
    Write-Info "Removing network: $NetworkName"
    podman network rm $NetworkName 2>$null | Out-Null
    
    Write-Success "Cleanup completed"
}

function New-PodmanNetwork {
    Write-Header "Creating Container Network"
    
    Write-Info "Creating network: $NetworkName"
    podman network create $NetworkName 2>$null | Out-Null
    
    if ($LASTEXITCODE -eq 0) {
        Write-Success "Network created: $NetworkName"
        return $true
    } else {
        # Network might already exist, check if it exists
        $networks = podman network ls --format "{{.Name}}"
        if ($networks -contains $NetworkName) {
            Write-Success "Network already exists: $NetworkName"
            return $true
        } else {
            Write-Error-Custom "Failed to create network: $NetworkName"
            return $false
        }
    }
}

function Start-DatabaseContainer {
    Write-Header "Starting Database Container"
    
    Write-Info "Starting MySQL database container..."
    
    # Use non-privileged port if MySQL port is 3306 and we're in rootless mode
    $mysqlPortMapping = if ($MySQLPort -lt 1024) { "3307:3306" } else { "$MySQLPort:3306" }
    
    podman run -d `
        --name $DBContainer `
        --network $NetworkName `
        -p $mysqlPortMapping `
        -e MYSQL_DATABASE=$DBName `
        -e MYSQL_USER=$DBUser `
        -e MYSQL_PASSWORD=$DBPassword `
        -e MYSQL_ROOT_PASSWORD=$DBRootPassword `
        -e MYSQL_INITDB_SKIP_TZINFO=1 `
        docker.io/library/mysql:8.0 `
        --default-authentication-plugin=mysql_native_password `
        --character-set-server=utf8mb4 `
        --collation-server=utf8mb4_unicode_ci
    
    if ($LASTEXITCODE -eq 0) {
        Write-Success "Database container started"
        
        # Wait for database to be ready
        Write-Info "Waiting for database to initialize..."
        $maxAttempts = 30
        $attempt = 0
        
        do {
            Start-Sleep -Seconds 2
            $attempt++
            Write-Info "Checking database readiness (attempt $attempt/$maxAttempts)..."
            
            # Test database connection
            podman exec $DBContainer mysqladmin ping -u root -p$DBRootPassword 2>$null | Out-Null
            $ready = ($LASTEXITCODE -eq 0)
            
        } while (-not $ready -and $attempt -lt $maxAttempts)
        
        if ($ready) {
            Write-Success "Database is ready"
            return $true
        } else {
            Write-Error-Custom "Database failed to start within $($maxAttempts * 2) seconds"
            return $false
        }
    } else {
        Write-Error-Custom "Failed to start database container"
        return $false
    }
}

function Start-WordPressContainer {
    Write-Header "Starting WordPress Container"
    
    Write-Info "Starting WordPress container with plugin mounted..."
    
    # Get absolute path to plugin source
    $currentDir = Get-Location
    $pluginSourcePath = Join-Path (Split-Path $currentDir -Parent) "src"
    
    if (-not (Test-Path $pluginSourcePath)) {
        Write-Error-Custom "Plugin source directory not found: $pluginSourcePath"
        return $false
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
        -e "WORDPRESS_CONFIG_EXTRA=define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false); define('SCRIPT_DEBUG', true); define('WP_MEMORY_LIMIT', '512M'); define('FS_METHOD', 'direct');" `
        -v "${pluginSourcePath}:/var/www/html/wp-content/plugins/webp-safe-migrator" `
        -v "${InstallPath}\uploads:/var/www/html/wp-content/uploads" `
        docker.io/library/wordpress:latest
    
    if ($LASTEXITCODE -eq 0) {
        Write-Success "WordPress container started"
        Write-Info "Plugin mounted from: $pluginSourcePath"
        return $true
    } else {
        Write-Error-Custom "Failed to start WordPress container"
        return $false
    }
}

function Start-PhpMyAdminContainer {
    Write-Header "Starting phpMyAdmin Container"
    
    Write-Info "Starting phpMyAdmin container..."
    
    podman run -d `
        --name $PhpMyAdminContainer `
        --network $NetworkName `
        -p "${PhpMyAdminPort}:80" `
        -e PMA_HOST=$DBContainer `
        -e PMA_USER=root `
        -e PMA_PASSWORD=$DBRootPassword `
        -e PMA_ARBITRARY=1 `
        -e UPLOAD_LIMIT=100M `
        docker.io/library/phpmyadmin:latest
    
    if ($LASTEXITCODE -eq 0) {
        Write-Success "phpMyAdmin container started"
        return $true
    } else {
        Write-Error-Custom "Failed to start phpMyAdmin container"
        return $false
    }
}

function Start-WPCLIContainer {
    Write-Header "Starting WP-CLI Container"
    
    Write-Info "Starting WP-CLI container..."
    
    # Get absolute path to plugin source
    $currentDir = Get-Location
    $pluginSourcePath = Join-Path (Split-Path $currentDir -Parent) "src"
    
    podman run -d `
        --name $WPCLIContainer `
        --network $NetworkName `
        -e WORDPRESS_DB_HOST=$DBContainer `
        -e WORDPRESS_DB_USER=$DBUser `
        -e WORDPRESS_DB_PASSWORD=$DBPassword `
        -e WORDPRESS_DB_NAME=$DBName `
        -v "wordpress_data:/var/www/html" `
        -v "${pluginSourcePath}:/var/www/html/wp-content/plugins/webp-safe-migrator" `
        --entrypoint "" `
        docker.io/library/wordpress:cli `
        tail -f /dev/null
    
    if ($LASTEXITCODE -eq 0) {
        Write-Success "WP-CLI container started"
        return $true
    } else {
        Write-Error-Custom "Failed to start WP-CLI container"
        return $false
    }
}

function Wait-ForWordPress {
    Write-Header "Waiting for WordPress to be Ready"
    
    $maxAttempts = 30
    $attempt = 0
    $wordpressUrl = "http://localhost:$HttpPort"
    
    Write-Info "Checking WordPress availability at: $wordpressUrl"
    
    do {
        Start-Sleep -Seconds 2
        $attempt++
        Write-Info "Testing WordPress (attempt $attempt/$maxAttempts)..."
        
        try {
            $response = Invoke-WebRequest -Uri $wordpressUrl -TimeoutSec 5 -UseBasicParsing -ErrorAction SilentlyContinue
            $ready = ($response.StatusCode -eq 200) -or ($response.StatusCode -ge 300 -and $response.StatusCode -lt 400)
        }
        catch {
            $ready = $false
        }
        
    } while (-not $ready -and $attempt -lt $maxAttempts)
    
    if ($ready) {
        Write-Success "WordPress is accessible"
        return $true
    } else {
        Write-Warning "WordPress may not be fully ready, but continuing..."
        return $false
    }
}

function Install-WordPress {
    Write-Header "Installing WordPress"
    
    Write-Info "Installing WordPress core..."
    
    $wordpressUrl = "http://localhost:$HttpPort"
    
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
        Write-Success "WordPress core installed successfully"
        return $true
    } else {
        Write-Error-Custom "Failed to install WordPress core"
        return $false
    }
}

function Install-WebPMigratorPlugin {
    Write-Header "Installing WebP Safe Migrator Plugin"
    
    Write-Info "Activating WebP Safe Migrator plugin..."
    
    podman exec $WPCLIContainer wp plugin activate webp-safe-migrator --allow-root
    
    if ($LASTEXITCODE -eq 0) {
        Write-Success "WebP Safe Migrator plugin activated"
        
        # Create sample content for testing
        Write-Info "Creating sample content..."
        podman exec $WPCLIContainer wp post create `
            --post_type=page `
            --post_title="WebP Migrator Test Guide" `
            --post_content="<h2>Welcome to WebP Safe Migrator Test Site</h2><p>This site is set up for testing the WebP Safe Migrator plugin.</p><h3>Quick Start:</h3><ol><li>Go to <strong>Media → WebP Migrator</strong></li><li>Upload some test images</li><li>Configure quality settings (recommended: 75)</li><li>Set batch size (start with 5-10)</li><li>Click 'Process next batch'</li><li>Review converted images</li></ol><p><strong>Admin Credentials:</strong><br>Username: $AdminUser<br>Password: $AdminPassword</p>" `
            --post_status=publish `
            --allow-root
        
        return $true
    } else {
        Write-Error-Custom "Failed to activate WebP Safe Migrator plugin"
        return $false
    }
}

function Show-AccessInformation {
    Write-Header "🎉 Deployment Complete!"
    
    $wordpressUrl = "http://localhost:$HttpPort"
    $adminUrl = "$wordpressUrl/wp-admin"
    $phpmyadminUrl = "http://localhost:$PhpMyAdminPort"
    
    Write-Host ""
    Write-Host "📍 Access URLs:" -ForegroundColor Cyan
    Write-Host "   🌐 WordPress Site: $wordpressUrl" -ForegroundColor White
    Write-Host "   🔧 WordPress Admin: $adminUrl" -ForegroundColor White  
    Write-Host "   🗄️  phpMyAdmin: $phpmyadminUrl" -ForegroundColor White
    Write-Host ""
    Write-Host "🔑 WordPress Login Credentials:" -ForegroundColor Cyan
    Write-Host "   👤 Username: $AdminUser" -ForegroundColor Yellow
    Write-Host "   🔑 Password: $AdminPassword" -ForegroundColor Yellow
    Write-Host "   📧 Email: $AdminEmail" -ForegroundColor White
    Write-Host ""
    Write-Host "🗄️  Database Information:" -ForegroundColor Cyan
    Write-Host "   📊 Database: $DBName" -ForegroundColor White
    Write-Host "   👤 DB User: $DBUser" -ForegroundColor White  
    Write-Host "   🔑 DB Password: $DBPassword" -ForegroundColor Yellow
    Write-Host "   🔑 Root Password: $DBRootPassword" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "🔌 Plugin Information:" -ForegroundColor Cyan
    Write-Host "   ✅ WebP Safe Migrator plugin is installed and activated!" -ForegroundColor Green
    Write-Host "   🎯 Access plugin at: Media → WebP Migrator" -ForegroundColor White
    Write-Host ""
    Write-Host "🛠️  Container Management:" -ForegroundColor Cyan
    Write-Host "   Check status: podman ps" -ForegroundColor White
    Write-Host "   Stop all: podman stop $WordPressContainer $DBContainer $PhpMyAdminContainer $WPCLIContainer" -ForegroundColor White
    Write-Host "   Start all: podman start $DBContainer $WordPressContainer $PhpMyAdminContainer $WPCLIContainer" -ForegroundColor White
    Write-Host ""
    
    if (-not $SkipBrowser) {
        Write-Info "Opening WordPress in your browser..."
        Start-Sleep -Seconds 2
        Start-Process $wordpressUrl
    }
}

function Show-ContainerStatus {
    Write-Header "Container Status"
    
    Write-Info "Current container status:"
    podman ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
}

# Main deployment function
function Start-WebPMigratorDeployment {
    Write-Header "WebP Safe Migrator - Automated Deployment"
    Write-Host "This script will create a complete WordPress development environment" -ForegroundColor White
    Write-Host "with the WebP Safe Migrator plugin pre-installed and configured." -ForegroundColor White
    Write-Host ""
    
    # Check prerequisites
    if (-not (Test-PodmanAvailable)) {
        return $false
    }
    
    # Create installation directory
    Write-Info "Creating installation directory: $InstallPath"
    New-Item -ItemType Directory -Force -Path "$InstallPath\uploads" | Out-Null
    
    # Cleanup existing containers if requested or if they exist
    if ($CleanStart) {
        Remove-ExistingContainers
    } else {
        # Check if containers already exist
        $existingContainers = podman ps -a --format "{{.Names}}" | Where-Object { $_ -in @($WordPressContainer, $DBContainer, $PhpMyAdminContainer, $WPCLIContainer) }
        if ($existingContainers) {
            Write-Warning "Found existing containers: $($existingContainers -join ', ')"
            Write-Warning "Use -CleanStart parameter to automatically remove them"
            $response = Read-Host "Remove existing containers? (y/N)"
            if ($response -eq 'y' -or $response -eq 'Y') {
                Remove-ExistingContainers
            } else {
                Write-Error-Custom "Cannot proceed with existing containers"
                return $false
            }
        }
    }
    
    # Deploy containers
    if (-not (New-PodmanNetwork)) { return $false }
    if (-not (Start-DatabaseContainer)) { return $false }
    if (-not (Start-WordPressContainer)) { return $false }
    if (-not (Start-PhpMyAdminContainer)) { return $false }
    if (-not (Start-WPCLIContainer)) { return $false }
    
    # Wait for services and install WordPress
    Wait-ForWordPress | Out-Null
    
    if (-not (Install-WordPress)) { return $false }
    if (-not (Install-WebPMigratorPlugin)) { return $false }
    
    # Show results
    Show-ContainerStatus
    Show-AccessInformation
    
    return $true
}

# Execute main deployment
try {
    $success = Start-WebPMigratorDeployment
    
    if ($success) {
        Write-Success "🎉 WebP Safe Migrator deployment completed successfully!"
        exit 0
    } else {
        Write-Error-Custom "❌ Deployment failed!"
        exit 1
    }
}
catch {
    Write-Error-Custom "❌ Deployment failed with error: $($_.Exception.Message)"
    Write-Info "Run with -CleanStart parameter to clean up and retry"
    exit 1
}
