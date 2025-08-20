# WebP Safe Migrator Plugin Management Script - ENHANCED
# Handles complete plugin lifecycle with WordPress integration and database cleanup

param(
    [Parameter(Mandatory=$true)]
    [ValidateSet("install", "update", "uninstall", "backup", "restore", "activate", "deactivate", "status", "cleanup")]
    [string]$Action,
    
    [string]$WordPressPath = "C:\webp-migrator-test\wordpress",
    [string]$SourcePath = ".\src",
    [string]$BackupPath = ".\backups",
    [switch]$Force,
    [switch]$WithDatabase = $true
)

$ErrorActionPreference = "Stop"

# Configuration
$PluginSlug = "webp-safe-migrator"
$PluginDir = "$WordPressPath\wp-content\plugins\$PluginSlug"
$BackupTimestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$WPCLIPath = "$WordPressPath\wp-cli.phar"

Write-Host "=== WebP Safe Migrator Plugin Manager (ENHANCED) ===" -ForegroundColor Green
Write-Host "Action: $Action" -ForegroundColor Yellow
Write-Host "WordPress Path: $WordPressPath" -ForegroundColor Yellow
Write-Host "Plugin Directory: $PluginDir" -ForegroundColor Yellow
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

function Install-WPCLI {
    if (Test-Path $WPCLIPath) {
        Write-Host "WP-CLI already installed." -ForegroundColor Green
        return $true
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
    
    if (-not (Test-Path $WPCLIPath)) {
        if (-not (Install-WPCLI)) {
            throw "WP-CLI is required but could not be installed"
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
    finally {
        Set-Location $originalLocation
    }
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
    
    # Backup database settings if WP-CLI is available
    if ($WithDatabase -and (Test-Path $WPCLIPath)) {
        Write-Host "Creating database backup..." -ForegroundColor Cyan
        
        $dbBackup = @{
            options = @()
            postmeta = @()
            timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
        }
        
        # Backup plugin options
        $optionsResult = Invoke-WPCLI "option list --search='webp_*' --format=json"
        if ($optionsResult.Success -and $optionsResult.Output) {
            $dbBackup.options = $optionsResult.Output | ConvertFrom-Json
        }
        
        # Backup plugin postmeta
        $postmetaResult = Invoke-WPCLI "db query ""SELECT * FROM wp_postmeta WHERE meta_key LIKE '_webp_%'"" --format=json"
        if ($postmetaResult.Success -and $postmetaResult.Output) {
            try {
                $dbBackup.postmeta = $postmetaResult.Output | ConvertFrom-Json
            }
            catch {
                # Handle case where no results are returned
                $dbBackup.postmeta = @()
            }
        }
        
        # Save database backup
        $dbBackup | ConvertTo-Json -Depth 10 | Set-Content "$BackupFullPath\database-backup.json"
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
    
    # Create uninstall.php for proper WordPress cleanup
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
"@
    
    Set-Content -Path "$PluginDir\uninstall.php" -Value $UninstallContent
    
    Write-Host "Plugin installed successfully!" -ForegroundColor Green
    Write-Host "Location: $PluginDir" -ForegroundColor Yellow
    
    # Try to activate plugin if WP-CLI is available
    if ($WithDatabase -and (Install-WPCLI)) {
        Write-Host "Attempting to activate plugin..." -ForegroundColor Cyan
        $activateResult = Invoke-WPCLI "plugin activate $PluginSlug"
        
        if ($activateResult.Success) {
            Write-Host "Plugin activated successfully!" -ForegroundColor Green
        } else {
            Write-Warning "Plugin activation failed. You can activate manually in WordPress admin."
        }
    }
    
    # Check if WordPress is accessible
    if (Test-NetConnection -ComputerName localhost -Port 8080 -InformationLevel Quiet) {
        Write-Host ""
        Write-Host "WordPress is running. Access:" -ForegroundColor Cyan
        Write-Host "1. Admin: http://localhost:8080/wp-admin/" -ForegroundColor White
        Write-Host "2. Plugin: Media → WebP Migrator" -ForegroundColor White
    }
}

function Uninstall-Plugin {
    Write-Host "Uninstalling WebP Safe Migrator plugin..." -ForegroundColor Cyan
    
    if (-not (Test-Path $PluginDir)) {
        Write-Host "Plugin is not installed." -ForegroundColor Yellow
        return
    }
    
    if (-not $Force) {
        $response = Read-Host "Are you sure you want to uninstall the plugin? This will remove all files and data. (y/N)"
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
    if ($WithDatabase -and (Test-Path $WPCLIPath)) {
        Write-Host "Deactivating plugin..." -ForegroundColor Cyan
        $deactivateResult = Invoke-WPCLI "plugin deactivate $PluginSlug"
        
        if ($deactivateResult.Success) {
            Write-Host "Plugin deactivated." -ForegroundColor Green
        }
        
        # Clean up database
        Write-Host "Cleaning up database..." -ForegroundColor Cyan
        
        # Remove options
        Invoke-WPCLI "option delete webp_safe_migrator_settings" | Out-Null
        Invoke-WPCLI "option delete webp_migrator_queue" | Out-Null
        Invoke-WPCLI "option delete webp_migrator_progress" | Out-Null
        
        # Remove postmeta
        Invoke-WPCLI "db query ""DELETE FROM wp_postmeta WHERE meta_key LIKE '_webp_%'""" | Out-Null
        
        Write-Host "Database cleaned up." -ForegroundColor Green
    }
    
    # Remove plugin directory
    Remove-Item $PluginDir -Recurse -Force
    
    Write-Host "Plugin uninstalled successfully!" -ForegroundColor Green
}

function Get-PluginStatus {
    Write-Host "Getting plugin status..." -ForegroundColor Cyan
    
    $status = @{
        Installed = Test-Path $PluginDir
        Active = $false
        Version = "Unknown"
        DatabaseClean = $true
    }
    
    if ($status.Installed) {
        # Get version
        $mainFile = Get-ChildItem -Path $PluginDir -Name "*.php" | Where-Object { $_ -like "*webp*migrator*.php" } | Select-Object -First 1
        if ($mainFile) {
            $content = Get-Content "$PluginDir\$mainFile" -Head 20
            $versionLine = $content | Where-Object { $_ -match "Version:\s*(.+)" }
            if ($versionLine) {
                $status.Version = $matches[1].Trim()
            }
        }
        
        # Check if active via WP-CLI
        if (Test-Path $WPCLIPath) {
            $activeResult = Invoke-WPCLI "plugin is-active $PluginSlug"
            $status.Active = $activeResult.Success
            
            # Check database status
            $optionsResult = Invoke-WPCLI "option list --search='webp_*' --format=count"
            $postmetaResult = Invoke-WPCLI "db query ""SELECT COUNT(*) as count FROM wp_postmeta WHERE meta_key LIKE '_webp_%'"" --format=csv"
            
            $optionsCount = if ($optionsResult.Success) { [int]$optionsResult.Output } else { 0 }
            $postmetaCount = if ($postmetaResult.Success) { 
                $csvData = $postmetaResult.Output | ConvertFrom-Csv
                [int]$csvData.count 
            } else { 0 }
            
            $status.DatabaseClean = ($optionsCount -eq 0 -and $postmetaCount -eq 0)
        }
    }
    
    return $status
}

function Show-PluginInfo {
    Write-Host ""
    Write-Host "=== Plugin Information ===" -ForegroundColor Cyan
    
    $status = Get-PluginStatus
    
    Write-Host "Status: $(if ($status.Installed) { 'Installed' } else { 'Not Installed' })" -ForegroundColor $(if ($status.Installed) { 'Green' } else { 'Red' })
    
    if ($status.Installed) {
        Write-Host "Location: $PluginDir" -ForegroundColor White
        Write-Host "Version: $($status.Version)" -ForegroundColor White
        Write-Host "Active: $(if ($status.Active) { 'Yes' } else { 'No' })" -ForegroundColor $(if ($status.Active) { 'Green' } else { 'Yellow' })
        Write-Host "Database Clean: $(if ($status.DatabaseClean) { 'Yes' } else { 'No' })" -ForegroundColor $(if ($status.DatabaseClean) { 'Green' } else { 'Yellow' })
        
        # Show directory size
        $size = (Get-ChildItem -Path $PluginDir -Recurse | Measure-Object -Property Length -Sum).Sum
        $sizeKB = [math]::Round($size / 1KB, 2)
        Write-Host "Size: $sizeKB KB" -ForegroundColor White
        
        # Count files
        $fileCount = (Get-ChildItem -Path $PluginDir -Recurse -File | Measure-Object).Count
        Write-Host "Files: $fileCount" -ForegroundColor White
    }
    
    # Show available backups
    if (Test-Path $BackupPath) {
        $backups = Get-ChildItem -Path $BackupPath -Directory -Name "$PluginSlug-*"
        if ($backups.Count -gt 0) {
            Write-Host ""
            Write-Host "Available Backups: $($backups.Count)" -ForegroundColor Cyan
            $backups | ForEach-Object { Write-Host "  $_" -ForegroundColor Gray }
        }
    }
    
    # Show WP-CLI status
    Write-Host ""
    Write-Host "WP-CLI Available: $(if (Test-Path $WPCLIPath) { 'Yes' } else { 'No' })" -ForegroundColor $(if (Test-Path $WPCLIPath) { 'Green' } else { 'Yellow' })
}

# Main execution
try {
    Test-WordPressInstallation
    
    switch ($Action) {
        "install" { Install-Plugin }
        "update" { 
            if (Test-Path $PluginDir) {
                Backup-Plugin -BackupName "pre-update-$BackupTimestamp"
                Remove-Item $PluginDir -Recurse -Force
            }
            Install-Plugin 
        }
        "uninstall" { Uninstall-Plugin }
        "backup" { Backup-Plugin }
        "restore" { Restore-Plugin }
        "activate" {
            if (Install-WPCLI) {
                $result = Invoke-WPCLI "plugin activate $PluginSlug"
                if ($result.Success) {
                    Write-Host "Plugin activated successfully!" -ForegroundColor Green
                } else {
                    Write-Host "Plugin activation failed." -ForegroundColor Red
                }
            }
        }
        "deactivate" {
            if (Install-WPCLI) {
                $result = Invoke-WPCLI "plugin deactivate $PluginSlug"
                if ($result.Success) {
                    Write-Host "Plugin deactivated successfully!" -ForegroundColor Green
                } else {
                    Write-Host "Plugin deactivation failed." -ForegroundColor Red
                }
            }
        }
        "status" { Show-PluginInfo; return }
        "cleanup" {
            if ($WithDatabase -and (Install-WPCLI)) {
                Write-Host "Cleaning up plugin database entries..." -ForegroundColor Cyan
                Invoke-WPCLI "option delete webp_safe_migrator_settings" | Out-Null
                Invoke-WPCLI "option delete webp_migrator_queue" | Out-Null  
                Invoke-WPCLI "option delete webp_migrator_progress" | Out-Null
                Invoke-WPCLI "db query ""DELETE FROM wp_postmeta WHERE meta_key LIKE '_webp_%'""" | Out-Null
                Write-Host "Database cleanup completed." -ForegroundColor Green
            }
        }
    }
    
    Show-PluginInfo
}
catch {
    Write-Host "Error: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "Operation completed successfully!" -ForegroundColor Green
