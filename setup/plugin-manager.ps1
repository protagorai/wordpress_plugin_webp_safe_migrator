# WebP Safe Migrator Plugin Management Script - ENHANCED
# Handles complete plugin lifecycle with database operations, API support, and optional WP-CLI integration

param(
    [Parameter(Mandatory=$true)]
    [ValidateSet("install", "update", "uninstall", "backup", "restore", "activate", "deactivate", "status", "cleanup", "setup-db")]
    [string]$Action,
    
    [string]$WordPressPath = "C:\webp-migrator-test\wordpress",
    [string]$SourcePath = ".\src",
    [string]$BackupPath = ".\backups",
    [switch]$Force,
    [switch]$UseWPCLI = $false,
    [switch]$AutoActivate = $true,
    [switch]$WithDatabase = $true,
    [switch]$SetupAPI = $false
)

$ErrorActionPreference = "Stop"

# Configuration
$PluginSlug = "webp-safe-migrator"
$PluginDir = "$WordPressPath\wp-content\plugins\$PluginSlug"
$BackupTimestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$WPCLIPath = "$WordPressPath\wp-cli.phar"
$WPConfigPath = "$WordPressPath\wp-config.php"

Write-Host "=== WebP Safe Migrator Plugin Manager (ENHANCED) ===" -ForegroundColor Green
Write-Host "Action: $Action" -ForegroundColor Yellow
Write-Host "WordPress Path: $WordPressPath" -ForegroundColor Yellow
Write-Host "Plugin Directory: $PluginDir" -ForegroundColor Yellow
Write-Host "Use WP-CLI: $UseWPCLI" -ForegroundColor Yellow
Write-Host "Database Operations: $WithDatabase" -ForegroundColor Yellow

function Test-WordPressInstallation {
    if (-not (Test-Path "$WordPressPath\wp-config.php")) {
        throw "WordPress installation not found at $WordPressPath"
    }
    
    if (-not (Test-Path "$WordPressPath\wp-content\plugins")) {
        throw "WordPress plugins directory not found"
    }
    
    Write-Host "WordPress installation verified." -ForegroundColor Green
}

function Get-WordPressConfig {
    if (-not (Test-Path $WPConfigPath)) {
        throw "WordPress configuration file not found"
    }
    
    $config = Get-Content $WPConfigPath -Raw
    
    # Extract database configuration
    $dbConfig = @{}
    
    if ($config -match "define\(\s*'DB_NAME',\s*'([^']+)'\s*\)") {
        $dbConfig.Name = $matches[1]
    }
    if ($config -match "define\(\s*'DB_USER',\s*'([^']+)'\s*\)") {
        $dbConfig.User = $matches[1]
    }
    if ($config -match "define\(\s*'DB_PASSWORD',\s*'([^']+)'\s*\)") {
        $dbConfig.Password = $matches[1]
    }
    if ($config -match "define\(\s*'DB_HOST',\s*'([^']+)'\s*\)") {
        $dbConfig.Host = $matches[1]
    }
    
    return $dbConfig
}

function Test-WPCLIAvailable {
    if ($UseWPCLI -and (Test-Path $WPCLIPath)) {
        return $true
    }
    return $false
}

function Install-WPCLI {
    if (Test-Path $WPCLIPath) {
        Write-Host "WP-CLI already installed." -ForegroundColor Green
        return $true
    }
    
    if (-not $UseWPCLI) {
        return $false
    }
    
    Write-Host "Installing WP-CLI..." -ForegroundColor Cyan
    try {
        Invoke-WebRequest -Uri "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar" -OutFile $WPCLIPath
        
        # Create WP-CLI batch wrapper
        $WPCLIBat = @"
@echo off
cd /d "$WordPressPath"
php "$WPCLIPath" %*
"@
        Set-Content -Path "$WordPressPath\wp.bat" -Value $WPCLIBat
        
        Write-Host "WP-CLI installed successfully." -ForegroundColor Green
        return $true
    }
    catch {
        Write-Warning "Failed to install WP-CLI: $($_.Exception.Message)"
        return $false
    }
}

function Invoke-WPCLI {
    param([string]$Command)
    
    if (-not (Test-WPCLIAvailable)) {
        if (-not (Install-WPCLI)) {
            Write-Warning "WP-CLI not available, falling back to direct methods"
            return @{ Success = $false; Output = "WP-CLI not available" }
        }
    }
    
    $originalLocation = Get-Location
    try {
        Set-Location $WordPressPath
        $result = & php $WPCLIPath $Command.Split(' ')
        return @{
            Success = $LASTEXITCODE -eq 0
            ExitCode = $LASTEXITCODE
            Output = $result
        }
    }
    catch {
        return @{
            Success = $false
            Output = $_.Exception.Message
        }
    }
    finally {
        Set-Location $originalLocation
    }
}

function Invoke-DatabaseQuery {
    param(
        [string]$Query,
        [switch]$ReturnResults = $false
    )
    
    if (-not $WithDatabase) {
        Write-Host "Database operations disabled." -ForegroundColor Yellow
        return $null
    }
    
    # Try WP-CLI first if available
    if (Test-WPCLIAvailable) {
        $format = if ($ReturnResults) { "--format=json" } else { "" }
        $result = Invoke-WPCLI "db query `"$Query`" $format"
        
        if ($result.Success) {
            if ($ReturnResults -and $result.Output) {
                try {
                    return $result.Output | ConvertFrom-Json
                }
                catch {
                    return $result.Output
                }
            }
            return $true
        }
    }
    
    # Fallback to direct MySQL connection
    $dbConfig = Get-WordPressConfig
    if ($dbConfig.Host -and $dbConfig.Name) {
        try {
            # Try to find MySQL executable
            $mysqlPaths = @(
                "$env:ProgramFiles\MySQL\MySQL Server*\bin\mysql.exe",
                "$env:ProgramFiles(x86)\MySQL\MySQL Server*\bin\mysql.exe",
                "C:\webp-migrator-test\mysql\mariadb-*\bin\mysql.exe"
            )
            
            $mysqlExe = $null
            foreach ($path in $mysqlPaths) {
                $found = Get-ChildItem $path -ErrorAction SilentlyContinue | Select-Object -First 1
                if ($found) {
                    $mysqlExe = $found.FullName
                    break
                }
            }
            
            if ($mysqlExe) {
                $args = @(
                    "-h", $dbConfig.Host,
                    "-u", $dbConfig.User,
                    "-p$($dbConfig.Password)",
                    $dbConfig.Name,
                    "-e", $Query
                )
                
                if ($ReturnResults) {
                    $args += "--batch"
                    $args += "--skip-column-names"
                }
                
                $result = & $mysqlExe $args
                
                if ($LASTEXITCODE -eq 0) {
                    return if ($ReturnResults) { $result } else { $true }
                }
            }
        }
        catch {
            Write-Warning "Direct MySQL query failed: $($_.Exception.Message)"
        }
    }
    
    Write-Warning "Could not execute database query. Install WP-CLI or ensure MySQL is accessible."
    return $false
}

function Setup-PluginDatabase {
    Write-Host "Setting up plugin database tables..." -ForegroundColor Cyan
    
    # The plugin doesn't need custom tables, but we can verify WordPress tables exist
    $queries = @(
        "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'wp_options'",
        "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'wp_postmeta'",
        "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'wp_posts'"
    )
    
    $allTablesExist = $true
    foreach ($query in $queries) {
        $result = Invoke-DatabaseQuery -Query $query -ReturnResults
        if (-not $result -or $result.count -eq 0) {
            $allTablesExist = $false
            break
        }
    }
    
    if ($allTablesExist) {
        Write-Host "WordPress database tables verified." -ForegroundColor Green
        
        # Initialize plugin options with defaults if they don't exist
        $defaultOptions = @{
            'webp_safe_migrator_settings' = @{
                'quality' = 59
                'batch_size' = 10
                'validation' = 1
                'skip_folders' = ''
                'skip_mimes' = ''
            }
        }
        
        foreach ($option in $defaultOptions.Keys) {
            $checkQuery = "SELECT COUNT(*) as count FROM wp_options WHERE option_name = '$option'"
            $exists = Invoke-DatabaseQuery -Query $checkQuery -ReturnResults
            
            if (-not $exists -or $exists.count -eq 0) {
                $value = $defaultOptions[$option] | ConvertTo-Json -Compress
                $insertQuery = "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('$option', '$value', 'yes')"
                
                if (Invoke-DatabaseQuery -Query $insertQuery) {
                    Write-Host "Initialized option: $option" -ForegroundColor Green
                }
            }
        }
        
        return $true
    }
    else {
        Write-Warning "WordPress database tables not found. Please ensure WordPress is properly installed."
        return $false
    }
}

function Cleanup-PluginDatabase {
    Write-Host "Cleaning up plugin database entries..." -ForegroundColor Cyan
    
    $queries = @(
        "DELETE FROM wp_options WHERE option_name LIKE 'webp_%'",
        "DELETE FROM wp_postmeta WHERE meta_key LIKE '_webp_%'",
        "DELETE FROM wp_options WHERE option_name = 'webp_migrator_queue'",
        "DELETE FROM wp_options WHERE option_name = 'webp_migrator_progress'"
    )
    
    $cleaned = 0
    foreach ($query in $queries) {
        if (Invoke-DatabaseQuery -Query $query) {
            $cleaned++
        }
    }
    
    # Clear scheduled hooks if WP-CLI is available
    if (Test-WPCLIAvailable) {
        Invoke-WPCLI "cron event delete webp_migrator_process_queue" | Out-Null
    }
    
    if ($cleaned -gt 0) {
        Write-Host "Database cleanup completed ($cleaned operations)." -ForegroundColor Green
        return $true
    }
    else {
        Write-Warning "Database cleanup may not have completed successfully."
        return $false
    }
}

function Get-PluginStatus {
    $status = @{
        Installed = Test-Path $PluginDir
        Active = $false
        Version = "Unknown"
        DatabaseClean = $true
        WPCLIAvailable = Test-WPCLIAvailable
        DatabaseConnected = $false
    }
    
    # Get version from plugin file
    if ($status.Installed) {
        $mainFile = Get-ChildItem -Path $PluginDir -Name "*.php" | Where-Object { $_ -like "*webp*migrator*.php" } | Select-Object -First 1
        if ($mainFile) {
            $content = Get-Content "$PluginDir\$mainFile" -Head 20
            $versionLine = $content | Where-Object { $_ -match "Version:\s*(.+)" }
            if ($versionLine) {
                $status.Version = $matches[1].Trim()
            }
        }
    }
    
    # Check database status
    if ($WithDatabase) {
        $optionsQuery = "SELECT COUNT(*) as count FROM wp_options WHERE option_name LIKE 'webp_%'"
        $postmetaQuery = "SELECT COUNT(*) as count FROM wp_postmeta WHERE meta_key LIKE '_webp_%'"
        
        $optionsResult = Invoke-DatabaseQuery -Query $optionsQuery -ReturnResults
        $postmetaResult = Invoke-DatabaseQuery -Query $postmetaQuery -ReturnResults
        
        if ($optionsResult -ne $false) {
            $status.DatabaseConnected = $true
            $optionsCount = if ($optionsResult.count) { $optionsResult.count } else { 0 }
            $postmetaCount = if ($postmetaResult.count) { $postmetaResult.count } else { 0 }
            $status.DatabaseClean = ($optionsCount -eq 0 -and $postmetaCount -eq 0)
        }
    }
    
    # Check if plugin is active (WP-CLI only)
    if (Test-WPCLIAvailable) {
        $activeResult = Invoke-WPCLI "plugin is-active $PluginSlug"
        $status.Active = $activeResult.Success
    }
    
    return $status
}

function Setup-PluginAPI {
    if (-not $SetupAPI) {
        return
    }
    
    Write-Host "Setting up plugin API endpoints..." -ForegroundColor Cyan
    
    # Create a simple API configuration file
    $apiConfig = @"
<?php
/**
 * WebP Safe Migrator API Configuration
 * Auto-generated by plugin manager
 */

// REST API endpoint registration
add_action('rest_api_init', function() {
    register_rest_route('webp-migrator/v1', '/status', array(
        'methods' => 'GET',
        'callback' => 'webp_migrator_api_status',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));
    
    register_rest_route('webp-migrator/v1', '/process', array(
        'methods' => 'POST',
        'callback' => 'webp_migrator_api_process',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));
});

function webp_migrator_api_status() {
    if (class_exists('WebP_Safe_Migrator')) {
        `$plugin = WebP_Safe_Migrator::instance();
        return array(
            'status' => 'active',
            'version' => '0.2.0',
            'queue_status' => 'ready'
        );
    }
    return new WP_Error('plugin_not_active', 'Plugin not active', array('status' => 500));
}

function webp_migrator_api_process() {
    // API processing logic would go here
    return array('message' => 'Processing started');
}
"@
    
    $apiFile = "$PluginDir\api-config.php"
    Set-Content -Path $apiFile -Value $apiConfig
    
    Write-Host "API configuration created at: $apiFile" -ForegroundColor Green
}

function Backup-Plugin {
    param([string]$BackupName = $BackupTimestamp)
    
    if (-not (Test-Path $PluginDir)) {
        Write-Host "No existing plugin to backup." -ForegroundColor Yellow
        return $null
    }
    
    $BackupFullPath = "$BackupPath\$PluginSlug-$BackupName"
    New-Item -ItemType Directory -Force -Path $BackupFullPath | Out-Null
    
    Write-Host "Creating plugin file backup..." -ForegroundColor Cyan
    Copy-Item "$PluginDir\*" $BackupFullPath -Recurse -Force
    
    # Backup database settings if enabled
    if ($WithDatabase) {
        Write-Host "Creating database backup..." -ForegroundColor Cyan
        
        $dbBackup = @{
            options = @()
            postmeta = @()
            timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
        }
        
        # Backup plugin options
        $optionsQuery = "SELECT option_name, option_value FROM wp_options WHERE option_name LIKE 'webp_%'"
        $optionsResult = Invoke-DatabaseQuery -Query $optionsQuery -ReturnResults
        if ($optionsResult) {
            $dbBackup.options = $optionsResult
        }
        
        # Backup plugin postmeta
        $postmetaQuery = "SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE meta_key LIKE '_webp_%'"
        $postmetaResult = Invoke-DatabaseQuery -Query $postmetaQuery -ReturnResults
        if ($postmetaResult) {
            $dbBackup.postmeta = $postmetaResult
        }
        
        # Save database backup
        $dbBackup | ConvertTo-Json -Depth 10 | Set-Content "$BackupFullPath\database-backup.json"
        Write-Host "Database backup included." -ForegroundColor Green
    }
    
    Write-Host "Plugin backed up to: $BackupFullPath" -ForegroundColor Green
    return $BackupFullPath
}

function Install-Plugin {
    Write-Host "Installing WebP Safe Migrator plugin..." -ForegroundColor Cyan
    
    # Verify source files exist
    if (-not (Test-Path $SourcePath)) {
        throw "Source directory not found: $SourcePath"
    }
    
    $MainPluginFile = Get-ChildItem -Path $SourcePath -Name "*.php" | Where-Object { $_ -like "*webp*migrator*.php" } | Select-Object -First 1
    if (-not $MainPluginFile) {
        throw "Main plugin file not found in source directory"
    }
    
    # Create plugin directory
    if (Test-Path $PluginDir) {
        if (-not $Force) {
            $response = Read-Host "Plugin directory exists. Overwrite? (y/N)"
            if ($response -ne 'y' -and $response -ne 'Y') {
                Write-Host "Installation cancelled." -ForegroundColor Yellow
                return
            }
        }
        
        # Backup existing installation
        Backup-Plugin
        Remove-Item $PluginDir -Recurse -Force
    }
    
    New-Item -ItemType Directory -Force -Path $PluginDir | Out-Null
    
    # Copy plugin files
    Write-Host "Copying plugin files..." -ForegroundColor Cyan
    Copy-Item "$SourcePath\*" $PluginDir -Recurse -Force
    
    # Create or update uninstall.php for proper cleanup
    $UninstallContent = @"
<?php
/**
 * WebP Safe Migrator Uninstall Script
 * Cleans up all plugin data when uninstalled via WordPress admin
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin options
delete_option('webp_safe_migrator_settings');
delete_option('webp_migrator_queue');
delete_option('webp_migrator_progress');

// Remove all plugin postmeta
global `$wpdb;
`$wpdb->query("DELETE FROM {`$wpdb->postmeta} WHERE meta_key LIKE '_webp_%'");

// Remove backup directories
`$upload_dir = wp_get_upload_dir();
`$backup_dir = trailingslashit(`$upload_dir['basedir']) . 'webp-migrator-backup';
if (is_dir(`$backup_dir)) {
    function webp_migrator_rrmdir(`$dir) {
        if (!is_dir(`$dir)) return false;
        `$files = scandir(`$dir);
        foreach (`$files as `$f) {
            if (`$f === '.' || `$f === '..') continue;
            `$path = `$dir . DIRECTORY_SEPARATOR . `$f;
            if (is_dir(`$path)) webp_migrator_rrmdir(`$path); else @unlink(`$path);
        }
        return @rmdir(`$dir);
    }
    webp_migrator_rrmdir(`$backup_dir);
}

// Clear scheduled hooks
wp_clear_scheduled_hook('webp_migrator_process_queue');
"@
    
    Set-Content -Path "$PluginDir\uninstall.php" -Value $UninstallContent
    
    # Setup database if enabled
    if ($WithDatabase) {
        Setup-PluginDatabase | Out-Null
    }
    
    # Setup API if requested
    if ($SetupAPI) {
        Setup-PluginAPI
    }
    
    Write-Host "Plugin installed successfully!" -ForegroundColor Green
    Write-Host "Location: $PluginDir" -ForegroundColor Yellow
    
    # Auto-activate plugin if requested and WP-CLI is available
    if ($AutoActivate -and (Test-WPCLIAvailable -or $UseWPCLI)) {
        Write-Host "Attempting to activate plugin..." -ForegroundColor Cyan
        
        if (-not (Test-WPCLIAvailable)) {
            Install-WPCLI | Out-Null
        }
        
        if (Test-WPCLIAvailable) {
            $activateResult = Invoke-WPCLI "plugin activate $PluginSlug"
            
            if ($activateResult.Success) {
                Write-Host "Plugin activated successfully!" -ForegroundColor Green
            } else {
                Write-Warning "Plugin activation failed. You can activate manually in WordPress admin."
                Write-Host "Error: $($activateResult.Output)" -ForegroundColor Red
            }
        }
    }
    
    # Check if WordPress is accessible
    if (Test-NetConnection -ComputerName localhost -Port 8080 -InformationLevel Quiet) {
        Write-Host ""
        Write-Host "WordPress is running. Access:" -ForegroundColor Cyan
        Write-Host "1. Admin Panel: http://localhost:8080/wp-admin/" -ForegroundColor White
        Write-Host "2. Plugin Settings: Media → WebP Migrator" -ForegroundColor White
        if ($SetupAPI) {
            Write-Host "3. API Endpoint: http://localhost:8080/wp-json/webp-migrator/v1/status" -ForegroundColor White
        }
    }
}

function Update-Plugin {
    Write-Host "Updating WebP Safe Migrator plugin..." -ForegroundColor Cyan
    
    if (-not (Test-Path $PluginDir)) {
        Write-Host "Plugin not currently installed. Use 'install' action instead." -ForegroundColor Yellow
        return
    }
    
    # Always backup before updating
    $backupPath = Backup-Plugin -BackupName "pre-update-$BackupTimestamp"
    
    # Remove old files but keep configuration
    $configFiles = @("wp-config.php", "settings.json", "*.log")
    $tempConfigPath = "$env:TEMP\webp-migrator-config-$BackupTimestamp"
    New-Item -ItemType Directory -Force -Path $tempConfigPath | Out-Null
    
    foreach ($pattern in $configFiles) {
        $files = Get-ChildItem -Path $PluginDir -Name $pattern -ErrorAction SilentlyContinue
        foreach ($file in $files) {
            Copy-Item "$PluginDir\$file" $tempConfigPath -ErrorAction SilentlyContinue
        }
    }
    
    # Install new version
    Remove-Item $PluginDir -Recurse -Force
    New-Item -ItemType Directory -Force -Path $PluginDir | Out-Null
    Copy-Item "$SourcePath\*" $PluginDir -Recurse -Force
    
    # Restore configuration files
    $configFiles = Get-ChildItem -Path $tempConfigPath -ErrorAction SilentlyContinue
    foreach ($file in $configFiles) {
        Copy-Item $file.FullName "$PluginDir\$($file.Name)" -Force -ErrorAction SilentlyContinue
    }
    
    # Cleanup temp directory
    Remove-Item $tempConfigPath -Recurse -Force -ErrorAction SilentlyContinue
    
    Write-Host "Plugin updated successfully!" -ForegroundColor Green
    Write-Host "Backup created at: $backupPath" -ForegroundColor Yellow
}

function Uninstall-Plugin {
    Write-Host "Uninstalling WebP Safe Migrator plugin..." -ForegroundColor Cyan
    
    if (-not (Test-Path $PluginDir)) {
        Write-Host "Plugin is not installed." -ForegroundColor Yellow
        return
    }
    
    if (-not $Force) {
        $response = Read-Host "Are you sure you want to uninstall the plugin? This will remove all files and database data. (y/N)"
        if ($response -ne 'y' -and $response -ne 'Y') {
            Write-Host "Uninstallation cancelled." -ForegroundColor Yellow
            return
        }
        
        $backupResponse = Read-Host "Create backup before uninstalling? (Y/n)"
        if ($backupResponse -ne 'n' -and $backupResponse -ne 'N') {
            Backup-Plugin -BackupName "pre-uninstall-$BackupTimestamp"
        }
    }
    
    # Deactivate plugin first if WP-CLI is available
    if (Test-WPCLIAvailable -or $UseWPCLI) {
        Write-Host "Deactivating plugin..." -ForegroundColor Cyan
        
        if (-not (Test-WPCLIAvailable)) {
            Install-WPCLI | Out-Null
        }
        
        if (Test-WPCLIAvailable) {
            $deactivateResult = Invoke-WPCLI "plugin deactivate $PluginSlug"
            if ($deactivateResult.Success) {
                Write-Host "Plugin deactivated." -ForegroundColor Green
            }
        }
    }
    
    # Clean up database if enabled
    if ($WithDatabase) {
        Cleanup-PluginDatabase | Out-Null
        
        # Remove backup directories
        Write-Host "Removing backup directories..." -ForegroundColor Cyan
        $dbConfig = Get-WordPressConfig
        if ($dbConfig.Name) {
            # Get upload directory from WordPress
            $uploadQuery = "SELECT option_value FROM wp_options WHERE option_name = 'upload_path'"
            $uploadResult = Invoke-DatabaseQuery -Query $uploadQuery -ReturnResults
            
            if ($uploadResult -and $uploadResult.option_value) {
                $uploadPath = $uploadResult.option_value
            } else {
                $uploadPath = "$WordPressPath\wp-content\uploads"
            }
            
            $backupDir = "$uploadPath\webp-migrator-backup"
            if (Test-Path $backupDir) {
                Remove-Item $backupDir -Recurse -Force -ErrorAction SilentlyContinue
                Write-Host "Backup directories removed." -ForegroundColor Green
            }
        }
    }
    
    # Remove plugin directory
    Remove-Item $PluginDir -Recurse -Force
    
    Write-Host "Plugin uninstalled successfully!" -ForegroundColor Green
    
    if ($WithDatabase) {
        Write-Host "All plugin data has been removed from the database." -ForegroundColor Green
    } else {
        Write-Host ""
        Write-Host "Note: Database operations were disabled." -ForegroundColor Yellow
        Write-Host "To manually remove plugin data, run:" -ForegroundColor Yellow
        Write-Host "  .\setup\plugin-manager.ps1 cleanup -WithDatabase" -ForegroundColor Gray
    }
}

function Restore-Plugin {
    Write-Host "Restoring WebP Safe Migrator plugin from backup..." -ForegroundColor Cyan
    
    if (-not (Test-Path $BackupPath)) {
        throw "Backup directory not found: $BackupPath"
    }
    
    # List available backups
    $backups = Get-ChildItem -Path $BackupPath -Directory -Name "$PluginSlug-*" | Sort-Object -Descending
    
    if ($backups.Count -eq 0) {
        Write-Host "No backups found." -ForegroundColor Yellow
        return
    }
    
    Write-Host "Available backups:" -ForegroundColor Cyan
    for ($i = 0; $i -lt $backups.Count; $i++) {
        Write-Host "  $($i + 1). $($backups[$i])" -ForegroundColor White
    }
    
    $selection = Read-Host "Select backup to restore (1-$($backups.Count))"
    $selectedIndex = [int]$selection - 1
    
    if ($selectedIndex -lt 0 -or $selectedIndex -ge $backups.Count) {
        Write-Host "Invalid selection." -ForegroundColor Red
        return
    }
    
    $selectedBackup = $backups[$selectedIndex]
    $backupFullPath = "$BackupPath\$selectedBackup"
    
    # Backup current installation if it exists
    if (Test-Path $PluginDir) {
        Backup-Plugin -BackupName "pre-restore-$BackupTimestamp"
        Remove-Item $PluginDir -Recurse -Force
    }
    
    # Restore from backup
    New-Item -ItemType Directory -Force -Path $PluginDir | Out-Null
    Copy-Item "$backupFullPath\*" $PluginDir -Recurse -Force
    
    Write-Host "Plugin restored from backup: $selectedBackup" -ForegroundColor Green
}

function Show-PluginInfo {
    Write-Host ""
    Write-Host "=== Plugin Information ===" -ForegroundColor Cyan
    
    $status = Get-PluginStatus
    
    Write-Host "Status: $(if ($status.Installed) { 'Installed' } else { 'Not Installed' })" -ForegroundColor $(if ($status.Installed) { 'Green' } else { 'Red' })
    
    if ($status.Installed) {
        Write-Host "Location: $PluginDir" -ForegroundColor White
        Write-Host "Version: $($status.Version)" -ForegroundColor White
        Write-Host "Active: $(if ($status.Active) { 'Yes' } else { 'Unknown' })" -ForegroundColor $(if ($status.Active) { 'Green' } else { 'Yellow' })
        
        # Show directory size
        $size = (Get-ChildItem -Path $PluginDir -Recurse | Measure-Object -Property Length -Sum).Sum
        $sizeKB = [math]::Round($size / 1KB, 2)
        Write-Host "Size: $sizeKB KB" -ForegroundColor White
        
        # Count files
        $fileCount = (Get-ChildItem -Path $PluginDir -Recurse -File | Measure-Object).Count
        Write-Host "Files: $fileCount" -ForegroundColor White
    }
    
    # Database status
    if ($WithDatabase) {
        Write-Host ""
        Write-Host "Database Status:" -ForegroundColor Cyan
        Write-Host "Connected: $(if ($status.DatabaseConnected) { 'Yes' } else { 'No' })" -ForegroundColor $(if ($status.DatabaseConnected) { 'Green' } else { 'Red' })
        Write-Host "Clean: $(if ($status.DatabaseClean) { 'Yes' } else { 'No' })" -ForegroundColor $(if ($status.DatabaseClean) { 'Green' } else { 'Yellow' })
    }
    
    # WP-CLI status
    Write-Host ""
    Write-Host "WP-CLI Available: $(if ($status.WPCLIAvailable) { 'Yes' } else { 'No' })" -ForegroundColor $(if ($status.WPCLIAvailable) { 'Green' } else { 'Yellow' })
    
    # Show available backups
    if (Test-Path $BackupPath) {
        $backups = Get-ChildItem -Path $BackupPath -Directory -Name "$PluginSlug-*"
        if ($backups.Count -gt 0) {
            Write-Host ""
            Write-Host "Available Backups: $($backups.Count)" -ForegroundColor Cyan
            $backups | Select-Object -First 5 | ForEach-Object { Write-Host "  $_" -ForegroundColor Gray }
            if ($backups.Count -gt 5) {
                Write-Host "  ... and $($backups.Count - 5) more" -ForegroundColor Gray
            }
        }
    }
}

# Main execution
try {
    Test-WordPressInstallation
    
    switch ($Action) {
        "install" { 
            Install-Plugin 
        }
        "update" { 
            Update-Plugin 
        }
        "uninstall" { 
            Uninstall-Plugin 
        }
        "backup" { 
            $backupPath = Backup-Plugin
            if ($backupPath) {
                Write-Host "Backup created successfully at: $backupPath" -ForegroundColor Green
            }
        }
        "restore" { 
            Restore-Plugin 
        }
        "activate" {
            if (Test-WPCLIAvailable -or $UseWPCLI) {
                if (-not (Test-WPCLIAvailable)) {
                    Install-WPCLI | Out-Null
                }
                
                if (Test-WPCLIAvailable) {
                    $result = Invoke-WPCLI "plugin activate $PluginSlug"
                    if ($result.Success) {
                        Write-Host "Plugin activated successfully!" -ForegroundColor Green
                    } else {
                        Write-Host "Plugin activation failed: $($result.Output)" -ForegroundColor Red
                    }
                }
            } else {
                Write-Host "Activation requires WP-CLI. Use -UseWPCLI switch or activate manually in WordPress admin." -ForegroundColor Yellow
            }
        }
        "deactivate" {
            if (Test-WPCLIAvailable -or $UseWPCLI) {
                if (-not (Test-WPCLIAvailable)) {
                    Install-WPCLI | Out-Null
                }
                
                if (Test-WPCLIAvailable) {
                    $result = Invoke-WPCLI "plugin deactivate $PluginSlug"
                    if ($result.Success) {
                        Write-Host "Plugin deactivated successfully!" -ForegroundColor Green
                    } else {
                        Write-Host "Plugin deactivation failed: $($result.Output)" -ForegroundColor Red
                    }
                }
            } else {
                Write-Host "Deactivation requires WP-CLI. Use -UseWPCLI switch or deactivate manually in WordPress admin." -ForegroundColor Yellow
            }
        }
        "status" { 
            Show-PluginInfo
            return
        }
        "cleanup" {
            if ($WithDatabase) {
                $result = Cleanup-PluginDatabase
                if ($result) {
                    Write-Host "Database cleanup completed successfully!" -ForegroundColor Green
                } else {
                    Write-Host "Database cleanup may have failed. Check the output above." -ForegroundColor Yellow
                }
            } else {
                Write-Host "Database operations are disabled. Use -WithDatabase switch." -ForegroundColor Yellow
            }
        }
        "setup-db" {
            if ($WithDatabase) {
                $result = Setup-PluginDatabase
                if ($result) {
                    Write-Host "Database setup completed successfully!" -ForegroundColor Green
                } else {
                    Write-Host "Database setup failed. Check the output above." -ForegroundColor Red
                }
            } else {
                Write-Host "Database operations are disabled. Use -WithDatabase switch." -ForegroundColor Yellow
            }
        }
    }
    
    if ($Action -ne "status") {
        Show-PluginInfo
    }
}
catch {
    Write-Host "Error: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "Stack trace: $($_.ScriptStackTrace)" -ForegroundColor Gray
    exit 1
}

Write-Host ""
Write-Host "Operation completed successfully!" -ForegroundColor Green

# Show usage examples
if ($Action -eq "status") {
    Write-Host ""
    Write-Host "=== Usage Examples ===" -ForegroundColor Cyan
    Write-Host "Install with auto-activation:" -ForegroundColor White
    Write-Host "  .\setup\plugin-manager.ps1 install -UseWPCLI -AutoActivate" -ForegroundColor Gray
    Write-Host ""
    Write-Host "Install with database setup and API:" -ForegroundColor White
    Write-Host "  .\setup\plugin-manager.ps1 install -WithDatabase -SetupAPI" -ForegroundColor Gray
    Write-Host ""
    Write-Host "Complete uninstall with database cleanup:" -ForegroundColor White
    Write-Host "  .\setup\plugin-manager.ps1 uninstall -WithDatabase -UseWPCLI" -ForegroundColor Gray
    Write-Host ""
    Write-Host "Database operations only:" -ForegroundColor White
    Write-Host "  .\setup\plugin-manager.ps1 setup-db -WithDatabase" -ForegroundColor Gray
    Write-Host "  .\setup\plugin-manager.ps1 cleanup -WithDatabase" -ForegroundColor Gray
}
