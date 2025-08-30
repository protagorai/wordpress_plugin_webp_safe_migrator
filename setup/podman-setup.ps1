# WebP Safe Migrator - Podman Development Environment for Windows
# Cross-platform WordPress development using Podman containers

param(
    [Parameter(Mandatory=$true)]
    [ValidateSet("up", "down", "restart", "logs", "status", "shell", "wp", "mysql", "install", "clean", "backup", "restore")]
    [string]$Action,
    
    [string]$InstallPath = "$env:USERPROFILE\webp-migrator-test",
    [switch]$Detach = $true,
    [switch]$Follow = $false
)

# Configuration
$NetworkName = "webp-migrator-net"
$VolumePrefix = "webp-migrator"
$WordPressContainer = "webp-wordpress"
$DBContainer = "webp-mysql"
$PhpMyAdminContainer = "webp-phpmyadmin"

Write-Host "=== WebP Safe Migrator - Podman Environment (Windows) ===" -ForegroundColor Green
Write-Host "Action: $Action" -ForegroundColor Yellow
Write-Host "Install Path: $InstallPath" -ForegroundColor Yellow

# Check dependencies
function Test-Dependencies {
    Write-Host "Checking dependencies..." -ForegroundColor Cyan
    
    # Check for Podman
    if (-not (Get-Command podman -ErrorAction SilentlyContinue)) {
        Write-Host "Podman not found. Please install Podman Desktop for Windows:" -ForegroundColor Red
        Write-Host "  Download: https://podman.io/getting-started/installation#windows" -ForegroundColor White
        Write-Host "" 
        Write-Host "Why Podman? It's fully open-source (Apache 2.0) with no licensing restrictions," -ForegroundColor Yellow
        Write-Host "unlike Docker Desktop which requires paid licenses for commercial use." -ForegroundColor Yellow
        exit 1
    }
    
    # Check if Podman machine is running
    try {
        $machineList = podman machine list 2>$null
        if (-not ($machineList -match "Currently running")) {
            Write-Host "Starting Podman machine..." -ForegroundColor Cyan
            try {
                podman machine start
            }
            catch {
                Write-Host "Initializing Podman machine..." -ForegroundColor Cyan
                podman machine init
                podman machine start
            }
        }
    }
    catch {
        Write-Warning "Could not check Podman machine status, but continuing..."
    }
    
    # Test Podman functionality
    try {
        podman info | Out-Null
        Write-Host "Podman is available and working." -ForegroundColor Green
    }
    catch {
        Write-Host "Podman is not working properly. Please check your installation." -ForegroundColor Red
        exit 1
    }
}

# Create network if it doesn't exist
function New-PodmanNetwork {
    try {
        $networks = podman network ls --format "{{.Name}}"
        if ($networks -notcontains $NetworkName) {
            Write-Host "Creating Podman network: $NetworkName" -ForegroundColor Cyan
            podman network create $NetworkName
        }
    }
    catch {
        Write-Warning "Could not create network, using default"
    }
}

# Start database container
function Start-Database {
    Write-Host "Starting MySQL database container..." -ForegroundColor Cyan
    
    # Remove existing container if it exists
    try { podman rm -f $DBContainer 2>$null } catch {}
    
    podman run -d `
        --name $DBContainer `
        --network $NetworkName `
        -p 3306:3306 `
        -e MYSQL_DATABASE=wordpress_webp_test `
        -e MYSQL_USER=wordpress `
        -e MYSQL_PASSWORD=wordpress123 `
        -e MYSQL_ROOT_PASSWORD=root123 `
        -v "${VolumePrefix}-db-data:/var/lib/mysql" `
        --restart unless-stopped `
        docker.io/library/mysql:8.0 `
        --default-authentication-plugin=mysql_native_password
    
    # Wait for database to be ready
    Write-Host "Waiting for database to be ready..." -ForegroundColor Cyan
    for ($i = 1; $i -le 30; $i++) {
        try {
            podman exec $DBContainer mysql -u root -proot123 -e "SELECT 1;" 2>$null | Out-Null
            Write-Host "Database is ready." -ForegroundColor Green
            return
        }
        catch {
            Start-Sleep -Seconds 2
        }
    }
    
    Write-Host "Database failed to start within 60 seconds." -ForegroundColor Red
    throw "Database startup timeout"
}

# Start WordPress container
function Start-WordPress {
    Write-Host "Starting WordPress container..." -ForegroundColor Cyan
    
    # Remove existing container if it exists
    try { podman rm -f $WordPressContainer 2>$null } catch {}
    
    # Ensure directories exist
    New-Item -ItemType Directory -Force -Path "$InstallPath\plugin-dev", "$InstallPath\uploads" | Out-Null
    
    # Copy source files to plugin dev directory
    $SourceDir = Join-Path (Split-Path $PSScriptRoot -Parent) "src"
    if (Test-Path $SourceDir) {
        Copy-Item "$SourceDir\*" "$InstallPath\plugin-dev\" -Recurse -Force
    }
    
    podman run -d `
        --name $WordPressContainer `
        --network $NetworkName `
        -p 8080:80 `
        -e WORDPRESS_DB_HOST=$DBContainer `
        -e WORDPRESS_DB_USER=wordpress `
        -e WORDPRESS_DB_PASSWORD=wordpress123 `
        -e WORDPRESS_DB_NAME=wordpress_webp_test `
        -e WORDPRESS_DEBUG=1 `
        -e "WORDPRESS_CONFIG_EXTRA=define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false); define('SCRIPT_DEBUG', true); define('WP_MEMORY_LIMIT', '512M'); define('FS_METHOD', 'direct');" `
        -v "${VolumePrefix}-wp-data:/var/www/html" `
        -v "${InstallPath}\plugin-dev:/var/www/html/wp-content/plugins/webp-safe-migrator" `
        --restart unless-stopped `
        docker.io/library/wordpress:latest
    
    # Wait for WordPress to be ready
    Write-Host "Waiting for WordPress to be ready..." -ForegroundColor Cyan
    for ($i = 1; $i -le 30; $i++) {
        try {
            $response = Invoke-WebRequest -Uri "http://localhost:8080" -UseBasicParsing -TimeoutSec 5
            if ($response.StatusCode -eq 200 -or $response.StatusCode -like "30*") {
                Write-Host "WordPress is ready." -ForegroundColor Green
                return
            }
        }
        catch {
            Start-Sleep -Seconds 2
        }
    }
    
    Write-Warning "WordPress may not be fully ready yet, but continuing..."
}

# Start phpMyAdmin container
function Start-PhpMyAdmin {
    Write-Host "Starting phpMyAdmin container..." -ForegroundColor Cyan
    
    # Remove existing container if it exists
    try { podman rm -f $PhpMyAdminContainer 2>$null } catch {}
    
    podman run -d `
        --name $PhpMyAdminContainer `
        --network $NetworkName `
        -p 8081:80 `
        -e PMA_HOST=$DBContainer `
        -e PMA_USER=root `
        -e PMA_PASSWORD=root123 `
        -e PMA_ARBITRARY=1 `
        --restart unless-stopped `
        docker.io/phpmyadmin:latest
    
    Write-Host "phpMyAdmin started." -ForegroundColor Green
}

# Install WP-CLI in WordPress container
function Install-WPCLIInContainer {
    Write-Host "Installing WP-CLI in WordPress container..." -ForegroundColor Cyan
    
    podman exec $WordPressContainer bash -c @"
        if [[ ! -f /usr/local/bin/wp ]]; then
            curl -o /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
            chmod +x /tmp/wp-cli.phar
            mv /tmp/wp-cli.phar /usr/local/bin/wp
        fi
"@
    
    Write-Host "WP-CLI installed in container." -ForegroundColor Green
}

# Main execution
switch ($Action) {
    "up" {
        Test-Dependencies
        
        Write-Host "Starting WebP Migrator Podman environment..." -ForegroundColor Cyan
        
        # Create necessary directories
        New-Item -ItemType Directory -Force -Path "$InstallPath\plugin-dev", "$InstallPath\uploads", "$InstallPath\backups" | Out-Null
        
        New-PodmanNetwork
        Start-Database
        Start-WordPress
        Start-PhpMyAdmin
        Install-WPCLIInContainer
        
        Write-Host "Environment started successfully!" -ForegroundColor Green
        Write-Host ""
        Write-Host "📍 Quick Access:" -ForegroundColor Cyan
        Write-Host "   WordPress: http://localhost:8080" -ForegroundColor White
        Write-Host "   Admin: http://localhost:8080/wp-admin" -ForegroundColor White
        Write-Host "   phpMyAdmin: http://localhost:8081" -ForegroundColor White
        Write-Host ""
        Write-Host "🔑 Database Credentials:" -ForegroundColor Cyan
        Write-Host "   Database: wordpress_webp_test" -ForegroundColor White
        Write-Host "   Username: wordpress" -ForegroundColor White
        Write-Host "   Password: wordpress123" -ForegroundColor White
        Write-Host "   Root Password: root123" -ForegroundColor White
        Write-Host ""
        Write-Host "🔧 Management:" -ForegroundColor Cyan
        Write-Host "   View logs: $($MyInvocation.MyCommand.Name) logs -Follow" -ForegroundColor White
        Write-Host "   WP-CLI: $($MyInvocation.MyCommand.Name) wp plugin list" -ForegroundColor White
        Write-Host "   Shell: $($MyInvocation.MyCommand.Name) shell" -ForegroundColor White
        Write-Host ""
        Write-Host "💡 Plugin Development:" -ForegroundColor Yellow
        Write-Host "   Edit files in: src/ (auto-synced to container)" -ForegroundColor White
        Write-Host "   Plugin location: $InstallPath\plugin-dev\" -ForegroundColor White
    }
    
    "down" {
        Write-Host "Stopping WebP Migrator Podman environment..." -ForegroundColor Cyan
        
        try { podman stop $WordPressContainer $DBContainer $PhpMyAdminContainer 2>$null } catch {}
        try { podman rm $WordPressContainer $DBContainer $PhpMyAdminContainer 2>$null } catch {}
        
        Write-Host "Environment stopped." -ForegroundColor Green
    }
    
    "restart" {
        Write-Host "Restarting WebP Migrator Podman environment..." -ForegroundColor Cyan
        
        try { podman restart $WordPressContainer $DBContainer $PhpMyAdminContainer 2>$null } catch {}
        
        Write-Host "Environment restarted." -ForegroundColor Green
    }
    
    "logs" {
        if ($Follow) {
            podman logs -f $WordPressContainer
        } else {
            Write-Host "=== WordPress Logs ===" -ForegroundColor Cyan
            podman logs --tail 20 $WordPressContainer
            Write-Host ""
            Write-Host "=== Database Logs ===" -ForegroundColor Cyan
            podman logs --tail 10 $DBContainer
        }
    }
    
    "status" {
        Write-Host "=== Container Status ===" -ForegroundColor Cyan
        
        # Check container status
        $containers = @($WordPressContainer, $DBContainer, $PhpMyAdminContainer)
        foreach ($container in $containers) {
            try {
                $status = podman ps --format "{{.Names}}" | Where-Object { $_ -eq $container }
                if ($status) {
                    Write-Host "$container`: " -NoNewline -ForegroundColor White
                    Write-Host "✓ Running" -ForegroundColor Green
                } else {
                    Write-Host "$container`: " -NoNewline -ForegroundColor White
                    Write-Host "✗ Stopped" -ForegroundColor Red
                }
            }
            catch {
                Write-Host "$container`: " -NoNewline -ForegroundColor White
                Write-Host "? Unknown" -ForegroundColor Yellow
            }
        }
        
        Write-Host ""
        Write-Host "=== Service Health ===" -ForegroundColor Cyan
        
        # Check WordPress
        try {
            $response = Invoke-WebRequest -Uri "http://localhost:8080" -UseBasicParsing -TimeoutSec 5
            Write-Host "WordPress: " -NoNewline -ForegroundColor White
            Write-Host "✓ Accessible (http://localhost:8080)" -ForegroundColor Green
        }
        catch {
            Write-Host "WordPress: " -NoNewline -ForegroundColor White
            Write-Host "✗ Not accessible" -ForegroundColor Red
        }
        
        # Check phpMyAdmin
        try {
            $response = Invoke-WebRequest -Uri "http://localhost:8081" -UseBasicParsing -TimeoutSec 5
            Write-Host "phpMyAdmin: " -NoNewline -ForegroundColor White
            Write-Host "✓ Accessible (http://localhost:8081)" -ForegroundColor Green
        }
        catch {
            Write-Host "phpMyAdmin: " -NoNewline -ForegroundColor White
            Write-Host "✗ Not accessible" -ForegroundColor Red
        }
        
        # Check database
        try {
            podman exec $DBContainer mysql -u wordpress -pwordpress123 -e "SELECT 1;" wordpress_webp_test 2>$null | Out-Null
            Write-Host "Database: " -NoNewline -ForegroundColor White
            Write-Host "✓ Connected" -ForegroundColor Green
        }
        catch {
            Write-Host "Database: " -NoNewline -ForegroundColor White
            Write-Host "✗ Connection failed" -ForegroundColor Red
        }
        
        # Check plugin files
        if ((Test-Path "$InstallPath\plugin-dev") -and (Get-ChildItem "$InstallPath\plugin-dev" -ErrorAction SilentlyContinue)) {
            Write-Host "Plugin Files: " -NoNewline -ForegroundColor White
            Write-Host "✓ Available" -ForegroundColor Green
        } else {
            Write-Host "Plugin Files: " -NoNewline -ForegroundColor White
            Write-Host "⚠ Not found" -ForegroundColor Yellow
        }
    }
    
    "shell" {
        Write-Host "Opening shell in WordPress container..." -ForegroundColor Cyan
        podman exec -it $WordPressContainer bash
    }
    
    "wp" {
        Write-Host "Executing WP-CLI command: $args" -ForegroundColor Cyan
        podman exec $WordPressContainer wp $args --allow-root
    }
    
    "mysql" {
        Write-Host "Opening MySQL shell..." -ForegroundColor Cyan
        podman exec -it $DBContainer mysql -u root -proot123 wordpress_webp_test
    }
    
    "install" {
        Write-Host "Installing WordPress and WebP Safe Migrator plugin..." -ForegroundColor Cyan
        
        # Ensure containers are running
        try {
            $wpStatus = podman ps --format "{{.Names}}" | Where-Object { $_ -eq $WordPressContainer }
            if (-not $wpStatus) {
                Write-Host "WordPress container not running. Run 'up' action first." -ForegroundColor Red
                exit 1
            }
        }
        catch {
            Write-Host "Could not check container status. Ensure containers are running." -ForegroundColor Red
            exit 1
        }
        
        # Wait for database
        Write-Host "Waiting for database to be ready..." -ForegroundColor Cyan
        for ($i = 1; $i -le 30; $i++) {
            try {
                podman exec $DBContainer mysql -u wordpress -pwordpress123 -e "SELECT 1;" wordpress_webp_test 2>$null | Out-Null
                break
            }
            catch {
                if ($i -eq 30) {
                    Write-Host "Database not ready after 60 seconds." -ForegroundColor Red
                    exit 1
                }
                Start-Sleep -Seconds 2
            }
        }
        
        # Install WordPress core
        Write-Host "Installing WordPress core..." -ForegroundColor Cyan
        podman exec $WordPressContainer wp core install `
            --url="http://localhost:8080" `
            --title="WebP Migrator Test Site" `
            --admin_user="admin" `
            --admin_password="admin123" `
            --admin_email="admin@webp-test.local" `
            --skip-email `
            --allow-root
        
        # Activate plugin
        Write-Host "Activating WebP Safe Migrator plugin..." -ForegroundColor Cyan
        podman exec $WordPressContainer wp plugin activate webp-safe-migrator --allow-root
        
        # Create test content
        Write-Host "Creating test content..." -ForegroundColor Cyan
        podman exec $WordPressContainer wp post create `
            --post_type=page `
            --post_title="WebP Migrator Test Guide" `
            --post_content="<h2>Welcome to WebP Safe Migrator Test Site</h2><p>Go to Media → WebP Migrator to start testing.</p>" `
            --post_status=publish `
            --allow-root
        
        Write-Host "WordPress and plugin installed successfully!" -ForegroundColor Green
        Write-Host ""
        Write-Host "🌐 WordPress: http://localhost:8080" -ForegroundColor Cyan
        Write-Host "🔧 Admin: http://localhost:8080/wp-admin" -ForegroundColor Cyan
        Write-Host "👤 Username: admin" -ForegroundColor Yellow
        Write-Host "🔑 Password: admin123" -ForegroundColor Yellow
    }
    
    "clean" {
        Write-Host "This will remove all containers and data!" -ForegroundColor Yellow
        $confirm = Read-Host "Are you sure? (y/N)"
        if ($confirm -eq 'y' -or $confirm -eq 'Y') {
            Write-Host "Removing containers and volumes..." -ForegroundColor Cyan
            
            # Stop and remove containers
            try { podman stop $WordPressContainer $DBContainer $PhpMyAdminContainer 2>$null } catch {}
            try { podman rm $WordPressContainer $DBContainer $PhpMyAdminContainer 2>$null } catch {}
            
            # Remove volumes
            try { podman volume rm "${VolumePrefix}-wp-data" "${VolumePrefix}-db-data" 2>$null } catch {}
            
            # Remove network
            try { podman network rm $NetworkName 2>$null } catch {}
            
            # Clean up local directories
            Remove-Item "$InstallPath\plugin-dev", "$InstallPath\uploads" -Recurse -Force -ErrorAction SilentlyContinue
            
            Write-Host "Environment cleaned." -ForegroundColor Green
        } else {
            Write-Host "Clean operation cancelled." -ForegroundColor Yellow
        }
    }
    
    "backup" {
        Write-Host "Creating backup of WordPress data..." -ForegroundColor Cyan
        $BackupDir = "$InstallPath\backups\podman-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
        New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null
        
        # Backup database
        Write-Host "Backing up database..." -ForegroundColor Cyan
        podman exec $DBContainer mysqldump -u root -proot123 wordpress_webp_test > "$BackupDir\database.sql"
        
        # Backup WordPress files
        Write-Host "Backing up WordPress files..." -ForegroundColor Cyan
        podman exec $WordPressContainer tar -czf /tmp/wordpress-backup.tar.gz -C /var/www/html wp-content
        podman cp "${WordPressContainer}:/tmp/wordpress-backup.tar.gz" "$BackupDir\"
        
        # Backup plugin development files
        if (Test-Path "$InstallPath\plugin-dev") {
            tar -czf "$BackupDir\plugin-dev.tar.gz" -C $InstallPath plugin-dev
        }
        
        Write-Host "Backup created at: $BackupDir" -ForegroundColor Green
    }
    
    "restore" {
        Write-Host "Restoring from backup..." -ForegroundColor Cyan
        
        if (-not (Test-Path "$InstallPath\backups")) {
            Write-Host "No backups directory found." -ForegroundColor Red
            exit 1
        }
        
        # List available backups
        $backups = Get-ChildItem "$InstallPath\backups" -Directory -Name "podman-*" | Sort-Object -Descending
        
        if ($backups.Count -eq 0) {
            Write-Host "No backups found." -ForegroundColor Red
            exit 1
        }
        
        Write-Host "Available backups:" -ForegroundColor Cyan
        for ($i = 0; $i -lt $backups.Count; $i++) {
            Write-Host "  $($i + 1). $($backups[$i])" -ForegroundColor White
        }
        
        $selection = Read-Host "Select backup to restore (1-$($backups.Count))"
        $selectedIndex = [int]$selection - 1
        
        if ($selectedIndex -lt 0 -or $selectedIndex -ge $backups.Count) {
            Write-Host "Invalid selection." -ForegroundColor Red
            exit 1
        }
        
        $selectedBackup = "$InstallPath\backups\$($backups[$selectedIndex])"
        
        # Restore database
        if (Test-Path "$selectedBackup\database.sql") {
            Write-Host "Restoring database..." -ForegroundColor Cyan
            Get-Content "$selectedBackup\database.sql" | podman exec -i $DBContainer mysql -u root -proot123 wordpress_webp_test
        }
        
        # Restore WordPress files
        if (Test-Path "$selectedBackup\wordpress-backup.tar.gz") {
            Write-Host "Restoring WordPress files..." -ForegroundColor Cyan
            podman cp "$selectedBackup\wordpress-backup.tar.gz" "${WordPressContainer}:/tmp/"
            podman exec $WordPressContainer tar -xzf /tmp/wordpress-backup.tar.gz -C /var/www/html
        }
        
        Write-Host "Restore completed." -ForegroundColor Green
    }
    
    default {
        Write-Host "Unknown action: $Action" -ForegroundColor Red
        Write-Host "Use -Action with: up, down, restart, logs, status, shell, wp, mysql, install, clean, backup, restore" -ForegroundColor Yellow
        exit 1
    }
}
