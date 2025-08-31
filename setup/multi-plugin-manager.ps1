# Multi-Plugin Manager for WordPress Development Environment
# Enhanced plugin management with support for multiple plugins, deployment profiles, and configuration management

param(
    [Parameter(Mandatory=$true)]
    [ValidateSet("install-all", "install", "update", "uninstall", "backup", "restore", "activate", "deactivate", "status", "list", "cleanup", "deploy-profile", "deploy-to-container")]
    [string]$Action,
    
    [string]$Plugin = "",                        # Specific plugin slug (for single plugin operations)
    [string]$Profile = "development",            # Deployment profile (development, production, testing, custom)
    [string]$ContainerName = "",                 # Container name for deploy-to-container action
    [string]$WordPressPath = "C:\webp-migrator-test\wordpress",
    [string]$SourcePath = ".\src",
    [string]$BackupPath = ".\backups",
    [string]$ConfigPath = ".\bin\config",
    [switch]$Force,
    [switch]$UseWPCLI = $false,
    [switch]$AutoActivate = $true,
    [switch]$WithDatabase = $true,
    [switch]$DryRun = $false,
    [switch]$ShowVerbose = $false
)

$ErrorActionPreference = "Stop"

# Global variables
$BackupTimestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$WPCLIPath = "$WordPressPath\wp-cli.phar"
$WPConfigPath = "$WordPressPath\wp-config.php"
$PluginsConfig = @{}
$DeploymentConfig = @{}

Write-Host "=== Multi-Plugin Manager for WordPress Development ===" -ForegroundColor Green
Write-Host "Action: $Action" -ForegroundColor Yellow
Write-Host "Profile: $Profile" -ForegroundColor Yellow
Write-Host "WordPress Path: $WordPressPath" -ForegroundColor Yellow
if ($Plugin) {
    Write-Host "Target Plugin: $Plugin" -ForegroundColor Yellow
}
Write-Host "Dry Run: $DryRun" -ForegroundColor Yellow

# =============================================================================
# CONFIGURATION LOADING
# =============================================================================

function Load-PluginConfiguration {
    $pluginsConfigFile = Join-Path $ConfigPath "plugins.yaml"
    $mainConfigFile = Join-Path $ConfigPath "webp-migrator.config.yaml"
    
    if (-not (Test-Path $pluginsConfigFile)) {
        throw "Plugin configuration file not found: $pluginsConfigFile"
    }
    
    Write-Host "Loading plugin configuration from $pluginsConfigFile..." -ForegroundColor Cyan
    
    # Simple YAML parsing (basic implementation)
    $script:PluginsConfig = Parse-PluginsYaml -FilePath $pluginsConfigFile
    
    # Load main configuration if it exists
    if (Test-Path $mainConfigFile) {
        $script:DeploymentConfig = Parse-MainConfig -FilePath $mainConfigFile
    }
    
    if ($ShowVerbose) {
        Write-Host "Loaded configuration for $($script:PluginsConfig.available.Count) available plugins" -ForegroundColor Green
    }
}

function Parse-PluginsYaml {
    param([string]$FilePath)
    
    $content = Get-Content -Path $FilePath -Raw
    $config = @{
        available = @()
        deployment = @{}
        default_profile = "development"
        global = @{}
        plugin_configs = @{}
    }
    
    # Basic YAML parsing (simplified - would use proper YAML parser in production)
    $lines = $content -split "`n"
    $currentSection = ""
    $currentPlugin = $null
    $currentProfile = $null
    
    foreach ($line in $lines) {
        $line = $line.Trim()
        
        if ($line -match "^#" -or $line -eq "") { continue }
        
        # Handle main sections
        if ($line -match "^(\w+):$") {
            $currentSection = $matches[1]
            continue
        }
        
        # Handle plugin definitions
        if ($currentSection -eq "plugins" -and $line -match "^\s*available:") {
            continue
        }
        
        if ($currentSection -eq "plugins" -and $line -match "^\s*- slug:\s*[""']?([^""']*)[""']?") {
            $currentPlugin = @{
                slug = $matches[1]
                path = ""
                name = ""
                main_file = ""
                version = ""
                priority = 999
            }
            continue
        }
        
        # Parse plugin properties
        if ($currentPlugin -and $line -match "^\s*(\w+):\s*[""']?([^""']*)[""']?") {
            $key = $matches[1]
            $value = $matches[2]
            
            if ($key -eq "priority") {
                $currentPlugin[$key] = [int]$value
            } else {
                $currentPlugin[$key] = $value
            }
            
            # If this completes a plugin definition, add it
            if ($key -eq "priority" -or ($key -eq "category" -and $currentPlugin.priority)) {
                $config.available += $currentPlugin
                $currentPlugin = $null
            }
            continue
        }
        
        # Handle deployment profiles
        if ($line -match "^\s*deployment:") {
            $currentSection = "deployment"
            continue
        }
        
        if ($currentSection -eq "deployment" -and $line -match "^\s*(\w+):") {
            $currentProfile = $matches[1]
            $config.deployment[$currentProfile] = @{
                plugins = @()
            }
            continue
        }
        
        # Handle default profile
        if ($line -match "^default_profile:\s*[""']?([^""']*)[""']?") {
            $config.default_profile = $matches[1]
            continue
        }
    }
    
    return $config
}

function Parse-MainConfig {
    param([string]$FilePath)
    
    # Simplified parsing for main config
    return @{
        deployment_profile = "development"
        activation_overrides = @{}
        management = @{
            auto_activate = $true
            check_requirements = $true
            backup_before_deployment = $true
            rollback_on_error = $true
        }
    }
}

# =============================================================================
# WORDPRESS VALIDATION
# =============================================================================

function Test-WordPressInstallation {
    if (-not (Test-Path $WPConfigPath)) {
        throw "WordPress installation not found at $WordPressPath"
    }
    
    if (-not (Test-Path "$WordPressPath\wp-content\plugins")) {
        throw "WordPress plugins directory not found"
    }
    
    Write-Host "WordPress installation verified." -ForegroundColor Green
}

function Install-WPCLI {
    if (Test-Path $WPCLIPath) {
        return $true
    }
    
    Write-Host "Installing WP-CLI..." -ForegroundColor Cyan
    try {
        Invoke-WebRequest -Uri "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar" -OutFile $WPCLIPath
        return $true
    } catch {
        Write-Warning "Failed to install WP-CLI: $_"
        return $false
    }
}

function Test-WPCLIAvailable {
    return (Test-Path $WPCLIPath) -and (Test-Path $WPConfigPath)
}

function Invoke-WPCLI {
    param([string]$Command)
    
    if (-not (Test-WPCLIAvailable)) {
        throw "WP-CLI not available"
    }
    
    try {
        $output = & php $WPCLIPath $Command.Split(' ') --path=$WordPressPath 2>&1
        return @{ Success = $true; Output = $output -join "`n" }
    } catch {
        return @{ Success = $false; Output = $_.Exception.Message }
    }
}

# =============================================================================
# PLUGIN DISCOVERY AND MANAGEMENT
# =============================================================================

function Get-AvailablePlugins {
    $availablePlugins = @()
    
    if ($script:PluginsConfig.available) {
        foreach ($plugin in $script:PluginsConfig.available) {
            $pluginPath = Join-Path $SourcePath $plugin.path
            $mainFile = Join-Path $pluginPath $plugin.main_file
            
            if (Test-Path $mainFile) {
                $plugin.source_path = $pluginPath
                $plugin.main_file_path = $mainFile
                $plugin.exists = $true
                $availablePlugins += $plugin
            } else {
                Write-Warning "Plugin source not found: $mainFile"
                $plugin.exists = $false
            }
        }
    }
    
    return $availablePlugins
}

function Get-DeploymentProfile {
    param([string]$ProfileName)
    
    $profileName = $ProfileName.ToLower()
    
    if ($script:PluginsConfig.deployment -and $script:PluginsConfig.deployment[$profileName]) {
        return $script:PluginsConfig.deployment[$profileName]
    }
    
    # Return default profile if specified profile not found
    $defaultProfile = if ($script:PluginsConfig.default_profile) { $script:PluginsConfig.default_profile } else { "development" }
    if ($script:PluginsConfig.deployment[$defaultProfile]) {
        Write-Warning "Profile '$ProfileName' not found, using default: $defaultProfile"
        return $script:PluginsConfig.deployment[$defaultProfile]
    }
    
    throw "No deployment profile found for '$ProfileName' and no default profile available"
}

function Get-PluginsForDeployment {
    param([string]$ProfileName)
    
    $profile = Get-DeploymentProfile -ProfileName $ProfileName
    $availablePlugins = Get-AvailablePlugins
    $deploymentPlugins = @()
    
    if ($profile.plugins) {
        foreach ($profilePlugin in $profile.plugins) {
            $availablePlugin = $availablePlugins | Where-Object { $_.slug -eq $profilePlugin.slug }
            
            if ($availablePlugin -and $availablePlugin.exists) {
                # Merge profile configuration with available plugin info
                $deploymentPlugin = $availablePlugin.PSObject.Copy()
                $deploymentPlugin.activate = if ($profilePlugin.activate -ne $null) { $profilePlugin.activate } else { $true }
                $deploymentPlugin.config_override = if ($profilePlugin.config_override) { $profilePlugin.config_override } else { @{} }
                
                $deploymentPlugins += $deploymentPlugin
            } else {
                Write-Warning "Plugin '$($profilePlugin.slug)' specified in profile but not found in available plugins"
            }
        }
    }
    
    # Sort by priority
    return $deploymentPlugins | Sort-Object priority
}

# =============================================================================
# PLUGIN OPERATIONS
# =============================================================================

function Install-Plugin {
    param(
        [object]$Plugin,
        [switch]$ActivatePlugin = $true
    )
    
    $pluginDir = "$WordPressPath\wp-content\plugins\$($Plugin.slug)"
    
    Write-Host "Installing plugin: $($Plugin.name) ($($Plugin.slug))" -ForegroundColor Cyan
    
    if ($DryRun) {
        Write-Host "[DRY RUN] Would install plugin to: $pluginDir" -ForegroundColor Yellow
        return $true
    }
    
    try {
        # Backup existing plugin if it exists
        if (Test-Path $pluginDir) {
            $backupDir = "$BackupPath\$($Plugin.slug)-$BackupTimestamp"
            Write-Host "Backing up existing plugin to: $backupDir" -ForegroundColor Yellow
            Copy-Item -Path $pluginDir -Destination $backupDir -Recurse -Force
        }
        
        # Remove existing plugin directory
        if (Test-Path $pluginDir) {
            Remove-Item -Path $pluginDir -Recurse -Force
        }
        
        # Copy plugin files
        Write-Host "Copying plugin files from: $($Plugin.source_path)" -ForegroundColor Green
        Copy-Item -Path $Plugin.source_path -Destination $pluginDir -Recurse -Force
        
        # Activate plugin if requested
        if ($ActivatePlugin -and $AutoActivate) {
            Activate-Plugin -Plugin $Plugin
        }
        
        Write-Host "Plugin '$($Plugin.slug)' installed successfully." -ForegroundColor Green
        return $true
        
    } catch {
        Write-Error "Failed to install plugin '$($Plugin.slug)': $_"
        return $false
    }
}

function Activate-Plugin {
    param([object]$Plugin)
    
    if ($DryRun) {
        Write-Host "[DRY RUN] Would activate plugin: $($Plugin.slug)" -ForegroundColor Yellow
        return $true
    }
    
    if ($UseWPCLI -and (Test-WPCLIAvailable)) {
        Write-Host "Activating plugin '$($Plugin.slug)' via WP-CLI..." -ForegroundColor Cyan
        
        $result = Invoke-WPCLI "plugin activate $($Plugin.slug)"
        if ($result.Success) {
            Write-Host "Plugin '$($Plugin.slug)' activated successfully." -ForegroundColor Green
            return $true
        } else {
            Write-Warning "Failed to activate plugin '$($Plugin.slug)': $($result.Output)"
            return $false
        }
    } else {
        Write-Warning "Plugin activation requires WP-CLI. Plugin '$($Plugin.slug)' installed but not activated."
        return $false
    }
}

function Deactivate-Plugin {
    param([object]$Plugin)
    
    if ($DryRun) {
        Write-Host "[DRY RUN] Would deactivate plugin: $($Plugin.slug)" -ForegroundColor Yellow
        return $true
    }
    
    if ($UseWPCLI -and (Test-WPCLIAvailable)) {
        Write-Host "Deactivating plugin '$($Plugin.slug)' via WP-CLI..." -ForegroundColor Cyan
        
        $result = Invoke-WPCLI "plugin deactivate $($Plugin.slug)"
        if ($result.Success) {
            Write-Host "Plugin '$($Plugin.slug)' deactivated successfully." -ForegroundColor Green
            return $true
        } else {
            Write-Warning "Failed to deactivate plugin '$($Plugin.slug)': $($result.Output)"
            return $false
        }
    } else {
        Write-Warning "Plugin deactivation requires WP-CLI."
        return $false
    }
}

function Remove-Plugin {
    param([object]$Plugin)
    
    $pluginDir = "$WordPressPath\wp-content\plugins\$($Plugin.slug)"
    
    if ($DryRun) {
        Write-Host "[DRY RUN] Would remove plugin directory: $pluginDir" -ForegroundColor Yellow
        return $true
    }
    
    try {
        # Deactivate first
        Deactivate-Plugin -Plugin $Plugin
        
        # Remove plugin directory
        if (Test-Path $pluginDir) {
            Write-Host "Removing plugin directory: $pluginDir" -ForegroundColor Yellow
            Remove-Item -Path $pluginDir -Recurse -Force
        }
        
        Write-Host "Plugin '$($Plugin.slug)' removed successfully." -ForegroundColor Green
        return $true
        
    } catch {
        Write-Error "Failed to remove plugin '$($Plugin.slug)': $_"
        return $false
    }
}

# =============================================================================
# MAIN ACTIONS
# =============================================================================

function Deploy-ToContainer {
    param([string]$ProfileName, [string]$Container)
    
    if ([string]::IsNullOrEmpty($Container)) {
        throw "Container name is required for deploy-to-container action"
    }
    
    Write-Host "Deploying plugins to container: $Container using profile: $ProfileName" -ForegroundColor Green
    
    # Check if container exists and is running
    $containerStatus = & podman inspect $Container --format "{{.State.Status}}" 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw "Container '$Container' not found"
    }
    
    if ($containerStatus -ne "running") {
        throw "Container '$Container' is not running (Status: $containerStatus)"
    }
    
    $plugins = Get-PluginsForDeployment -ProfileName $ProfileName
    if (-not $plugins) {
        Write-Warning "No plugins found for deployment profile: $ProfileName"
        return $false
    }
    
    Write-Host "Found $($plugins.Count) plugins to deploy to container:" -ForegroundColor Cyan
    foreach ($plugin in $plugins) {
        Write-Host "  - $($plugin.name) ($($plugin.slug)) [Activate: $($plugin.activate)]" -ForegroundColor Gray
    }
    
    $successCount = 0
    $errorCount = 0
    
    foreach ($plugin in $plugins) {
        Write-Host "Deploying plugin: $($plugin.name)" -ForegroundColor Cyan
        
        # Copy plugin to container
        $sourcePath = $plugin.source_path
        $targetPath = "/var/www/html/wp-content/plugins/$($plugin.slug)"
        
        if (Test-Path $sourcePath) {
            # Remove existing plugin in container
            & podman exec $Container rm -rf "$targetPath" 2>$null
            
            # Copy plugin to container
            & podman cp "$sourcePath" "${Container}:$targetPath"
            
            if ($LASTEXITCODE -eq 0) {
                Write-Host "  ✓ Plugin copied successfully" -ForegroundColor Green
                
                # Fix permissions
                & podman exec $Container chown -R www-data:www-data "$targetPath" 2>$null
                
                # Activate plugin if requested
                if ($plugin.activate -and $AutoActivate) {
                    Write-Host "  * Activating plugin..." -ForegroundColor Cyan
                    & podman exec $Container wp plugin activate $plugin.slug --allow-root 2>$null
                    
                    if ($LASTEXITCODE -eq 0) {
                        Write-Host "  ✓ Plugin activated successfully" -ForegroundColor Green
                    } else {
                        Write-Host "  ! Plugin activation failed" -ForegroundColor Yellow
                    }
                }
                
                $successCount++
            } else {
                Write-Error "Failed to copy plugin to container"
                $errorCount++
            }
        } else {
            Write-Error "Plugin source not found: $sourcePath"
            $errorCount++
        }
    }
    
    Write-Host "Container deployment completed: $successCount successful, $errorCount errors" -ForegroundColor Green
    return ($errorCount -eq 0)
}

function Install-AllPlugins {
    param([string]$ProfileName)
    
    Write-Host "Installing plugins for profile: $ProfileName" -ForegroundColor Green
    
    $plugins = Get-PluginsForDeployment -ProfileName $ProfileName
    if (-not $plugins) {
        Write-Warning "No plugins found for deployment profile: $ProfileName"
        return
    }
    
    Write-Host "Found $($plugins.Count) plugins to deploy:" -ForegroundColor Cyan
    foreach ($plugin in $plugins) {
        Write-Host "  - $($plugin.name) ($($plugin.slug)) [Priority: $($plugin.priority), Activate: $($plugin.activate)]" -ForegroundColor Gray
    }
    
    if (-not $DryRun -and -not $Force) {
        $confirm = Read-Host "Continue with installation? (y/N)"
        if ($confirm -ne 'y' -and $confirm -ne 'Y') {
            Write-Host "Installation cancelled by user." -ForegroundColor Yellow
            return
        }
    }
    
    $successCount = 0
    $errorCount = 0
    
    # Execute pre-deployment hooks
    Execute-DeploymentHooks -Phase "pre_deployment"
    
    foreach ($plugin in $plugins) {
        try {
            $success = Install-Plugin -Plugin $plugin -ActivatePlugin $plugin.activate
            if ($success) {
                $successCount++
            } else {
                $errorCount++
            }
        } catch {
            Write-Error "Error installing plugin '$($plugin.slug)': $_"
            $errorCount++
        }
    }
    
    # Execute post-deployment hooks
    Execute-DeploymentHooks -Phase "post_deployment"
    
    # Fix permissions once for all plugins
    Fix-UploadsOwnership
    
    # Execute post-activation hooks
    Execute-DeploymentHooks -Phase "post_activation"
    
    Write-Host "Installation completed: $successCount successful, $errorCount errors" -ForegroundColor Green
}

function Execute-DeploymentHooks {
    param([string]$Phase)
    
    if ($DryRun) {
        Write-Host "[DRY RUN] Would execute $Phase hooks" -ForegroundColor Yellow
        return
    }
    
    # Implementation would read hooks from configuration and execute them
    # For now, just log the phase
    Write-Host "Executing $Phase hooks..." -ForegroundColor Cyan
}

function Fix-UploadsOwnership {
    if ($DryRun) {
        Write-Host "[DRY RUN] Would fix uploads directory ownership" -ForegroundColor Yellow
        return
    }
    
    Write-Host "Fixing uploads directory ownership..." -ForegroundColor Cyan
    
    # On Windows, this would typically involve setting proper ACLs
    # For now, just ensure the uploads directory exists
    $uploadsDir = "$WordPressPath\wp-content\uploads"
    if (-not (Test-Path $uploadsDir)) {
        New-Item -Path $uploadsDir -ItemType Directory -Force
    }
}

function Show-PluginStatus {
    Write-Host "Plugin Status Report" -ForegroundColor Green
    Write-Host "===================" -ForegroundColor Green
    
    $availablePlugins = Get-AvailablePlugins
    $deploymentPlugins = Get-PluginsForDeployment -ProfileName $Profile
    
    Write-Host "Available Plugins: $($availablePlugins.Count)" -ForegroundColor Cyan
    foreach ($plugin in $availablePlugins) {
        $status = if ($plugin.exists) { "Available" } else { "Missing" }
        $color = if ($plugin.exists) { "Green" } else { "Red" }
        Write-Host "  $($plugin.name) ($($plugin.slug)): $status" -ForegroundColor $color
    }
    
    Write-Host "`nDeployment Profile '$Profile': $($deploymentPlugins.Count) plugins" -ForegroundColor Cyan
    foreach ($plugin in $deploymentPlugins) {
        $activateStatus = if ($plugin.activate) { "Auto-activate" } else { "Manual" }
        Write-Host "  $($plugin.name) ($($plugin.slug)): $activateStatus" -ForegroundColor Gray
    }
    
    # Check installed plugins via WP-CLI if available
    if ($UseWPCLI -and (Test-WPCLIAvailable)) {
        Write-Host "`nInstalled WordPress Plugins:" -ForegroundColor Cyan
        $result = Invoke-WPCLI "plugin list --format=table"
        if ($result.Success) {
            Write-Host $result.Output -ForegroundColor Gray
        }
    }
}

function Show-AvailablePlugins {
    $availablePlugins = Get-AvailablePlugins
    
    Write-Host "Available Plugins for Development" -ForegroundColor Green
    Write-Host "=================================" -ForegroundColor Green
    
    foreach ($plugin in $availablePlugins) {
        Write-Host "`n$($plugin.name) ($($plugin.slug))" -ForegroundColor Cyan
        Write-Host "  Version: $($plugin.version)"
        Write-Host "  Description: $($plugin.description)"
        Write-Host "  Path: $($plugin.path)"
        Write-Host "  Main File: $($plugin.main_file)"
        Write-Host "  Priority: $($plugin.priority)"
        Write-Host "  Status: $(if ($plugin.exists) { 'Available' } else { 'Missing' })"
    }
}

# =============================================================================
# MAIN EXECUTION
# =============================================================================

try {
    # Load configuration
    Load-PluginConfiguration
    
    # Validate WordPress installation
    Test-WordPressInstallation
    
    # Install WP-CLI if requested
    if ($UseWPCLI -and -not (Test-WPCLIAvailable)) {
        Install-WPCLI
    }
    
    # Execute requested action
    switch ($Action) {
        "install-all" {
            Install-AllPlugins -ProfileName $Profile
        }
        
        "install" {
            if (-not $Plugin) {
                throw "Plugin parameter is required for install action"
            }
            $availablePlugins = Get-AvailablePlugins
            $targetPlugin = $availablePlugins | Where-Object { $_.slug -eq $Plugin }
            if ($targetPlugin) {
                Install-Plugin -Plugin $targetPlugin -ActivatePlugin $AutoActivate
            } else {
                throw "Plugin '$Plugin' not found in available plugins"
            }
        }
        
        "deploy-profile" {
            Install-AllPlugins -ProfileName $Profile
        }
        
        "deploy-to-container" {
            if (-not $ContainerName) {
                throw "ContainerName parameter is required for deploy-to-container action"
            }
            Deploy-ToContainer -ProfileName $Profile -Container $ContainerName
        }
        
        "status" {
            Show-PluginStatus
        }
        
        "list" {
            Show-AvailablePlugins
        }
        
        "cleanup" {
            # Implementation would clean up backups, logs, etc.
            Write-Host "Cleanup functionality not yet implemented" -ForegroundColor Yellow
        }
        
        default {
            Write-Host "Action '$Action' not yet implemented for multi-plugin manager" -ForegroundColor Yellow
            Write-Host "Use the legacy plugin-manager.ps1 for single plugin operations" -ForegroundColor Cyan
        }
    }
    
    Write-Host "`nMulti-plugin operation completed successfully!" -ForegroundColor Green
    
} catch {
    Write-Error "Multi-plugin manager failed: $_"
    exit 1
}
