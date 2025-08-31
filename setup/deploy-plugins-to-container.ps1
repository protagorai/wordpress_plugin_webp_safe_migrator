# Configuration-Driven Plugin Deployment to Container
# Reads plugins.yaml and deploys/activates plugins according to configuration

param(
    [Parameter(Mandatory=$true)]
    [string]$ContainerName,
    [string]$Profile = "development",
    [switch]$DryRun = $false
)

$ErrorActionPreference = "Continue"

Write-Host "=== Configuration-Driven Plugin Deployment ===" -ForegroundColor Green
Write-Host "Container: $ContainerName" -ForegroundColor Yellow
Write-Host "Profile: $Profile" -ForegroundColor Yellow
Write-Host "Dry Run: $DryRun" -ForegroundColor Yellow

function Read-PluginConfiguration {
    param([string]$ProfileName)
    
    $configFile = "bin/config/plugins.yaml"
    if (-not (Test-Path $configFile)) {
        Write-Warning "Configuration file not found: $configFile"
        return @()
    }
    
    Write-Host "Reading plugin configuration for profile: $ProfileName" -ForegroundColor Cyan
    
    $content = Get-Content $configFile -Raw
    $lines = $content -split "`n"
    
    $inDeployment = $false
    $inProfile = $false
    $deploymentPlugins = @()
    $currentPlugin = $null
    
    foreach ($line in $lines) {
        $line = $line.Trim()
        
        # Skip comments and empty lines
        if ($line -match "^#" -or $line -eq "") { continue }
        
        # Find deployment section
        if ($line -match "^deployment:") {
            $inDeployment = $true
            continue
        }
        
        # Find our profile
        if ($inDeployment -and $line -match "^${ProfileName}:") {
            $inProfile = $true
            Write-Host "  Found configuration for profile: $ProfileName" -ForegroundColor Green
            continue
        }
        
        # Stop parsing this profile if we hit another profile
        if ($inProfile -and $line -match "^\w+:" -and -not ($line -match "^\s")) {
            $inProfile = $false
        }
        
        # Parse plugin entries in the profile
        if ($inProfile -and $line -match "^\s*-\s+slug:\s*[""']?([^""']*)[""']?") {
            $currentPlugin = @{
                slug = $matches[1]
                activate = $true  # Default to activate
            }
            continue
        }
        
        # Parse activate setting for current plugin
        if ($inProfile -and $currentPlugin -and $line -match "^\s+activate:\s*(\w+)") {
            $currentPlugin.activate = ($matches[1] -eq "true")
            $deploymentPlugins += $currentPlugin
            $currentPlugin = $null
            continue
        }
    }
    
    Write-Host "Configuration loaded: $($deploymentPlugins.Count) plugins for $ProfileName" -ForegroundColor Green
    return $deploymentPlugins
}

function Get-AvailablePlugins {
    $plugins = @()
    
    if (Test-Path "src") {
        $pluginDirs = Get-ChildItem -Path "src" -Directory
        foreach ($dir in $pluginDirs) {
            $phpFiles = Get-ChildItem -Path $dir.FullName -Filter "*.php"
            if ($phpFiles.Count -gt 0) {
                $plugin = @{
                    slug = $dir.Name
                    name = $dir.Name
                    source_path = $dir.FullName
                    exists = $true
                }
                
                # Try to get plugin name from header
                $mainFile = $phpFiles | Where-Object { $_.Name -match $dir.Name } | Select-Object -First 1
                if (-not $mainFile) { $mainFile = $phpFiles[0] }
                
                $content = Get-Content $mainFile.FullName -Head 10 -ErrorAction SilentlyContinue
                $nameMatch = $content | Where-Object { $_ -match "Plugin Name:\s*(.+)" } | Select-Object -First 1
                if ($nameMatch -and $matches[1]) {
                    $plugin.name = $matches[1].Trim()
                }
                
                $plugins += $plugin
            }
        }
    }
    
    return $plugins
}

function Deploy-PluginsToContainer {
    param([string]$Container, [string]$ProfileName)
    
    # Check container exists and is running
    $containerCheck = & podman inspect $Container 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Container '$Container' not found. Make sure WordPress is running."
        return $false
    }
    
    # Get available plugins
    $availablePlugins = Get-AvailablePlugins
    Write-Host "Found $($availablePlugins.Count) available plugins in src/" -ForegroundColor Cyan
    
    # Get deployment configuration
    $deploymentConfig = Read-PluginConfiguration -ProfileName $ProfileName
    
    if ($deploymentConfig.Count -eq 0) {
        Write-Warning "No deployment configuration found for profile '$ProfileName'"
        Write-Host "Deploying all available plugins with default settings..." -ForegroundColor Yellow
        
        # Deploy all plugins, activate primary only
        foreach ($plugin in $availablePlugins) {
            $plugin.activate = ($plugin.slug -eq "okvir-image-safe-migrator")
        }
        $pluginsToDeploy = $availablePlugins
    } else {
        # Match configuration with available plugins
        $pluginsToDeploy = @()
        foreach ($configPlugin in $deploymentConfig) {
            $availablePlugin = $availablePlugins | Where-Object { $_.slug -eq $configPlugin.slug }
            if ($availablePlugin) {
                $availablePlugin.activate = $configPlugin.activate
                $pluginsToDeploy += $availablePlugin
            } else {
                Write-Warning "Plugin '$($configPlugin.slug)' in configuration but not found in src/"
            }
        }
    }
    
    if ($pluginsToDeploy.Count -eq 0) {
        Write-Warning "No plugins to deploy"
        return $false
    }
    
    Write-Host "`nDeployment Plan:" -ForegroundColor Cyan
    Write-Host "Profile: $ProfileName" -ForegroundColor Gray
    Write-Host "Container: $Container" -ForegroundColor Gray
    Write-Host "Plugins to deploy: $($pluginsToDeploy.Count)" -ForegroundColor Gray
    
    foreach ($plugin in $pluginsToDeploy) {
        $action = if ($plugin.activate) { "Deploy + Activate" } else { "Deploy Only" }
        Write-Host "  - $($plugin.name) ($($plugin.slug)): $action" -ForegroundColor Gray
    }
    
    if ($DryRun) {
        Write-Host "`n[DRY RUN] Deployment plan ready - no actual changes made" -ForegroundColor Yellow
        return $true
    }
    
    # Execute deployment
    Write-Host "`nExecuting deployment..." -ForegroundColor Green
    
    $deployedCount = 0
    $activatedCount = 0
    $errorCount = 0
    
    foreach ($plugin in $pluginsToDeploy) {
        Write-Host "`nDeploying: $($plugin.name)" -ForegroundColor Cyan
        
        $sourcePath = $plugin.source_path
        $targetPath = "/var/www/html/wp-content/plugins/$($plugin.slug)"
        
        # Remove existing plugin in container
        & podman exec $Container rm -rf "$targetPath" 2>$null
        
        # Copy plugin to container
        Write-Host "  * Copying plugin files..." -ForegroundColor Gray
        & podman cp "$sourcePath" "${Container}:$targetPath"
        
        if ($LASTEXITCODE -eq 0) {
            Write-Host "  ✓ Plugin deployed successfully" -ForegroundColor Green
            $deployedCount++
            
            # Fix permissions
            & podman exec $Container chown -R www-data:www-data "$targetPath" 2>$null
            
            # Activate if configured
            if ($plugin.activate) {
                Write-Host "  * Activating plugin..." -ForegroundColor Gray
                & podman exec $Container wp plugin activate $plugin.slug --allow-root 2>$null
                
                if ($LASTEXITCODE -eq 0) {
                    Write-Host "  ✓ Plugin activated successfully" -ForegroundColor Green
                    $activatedCount++
                } else {
                    Write-Host "  ! Plugin activation failed" -ForegroundColor Yellow
                }
            } else {
                Write-Host "  ○ Plugin deployed but not activated (per configuration)" -ForegroundColor Gray
            }
        } else {
            Write-Host "  ✗ Plugin deployment failed" -ForegroundColor Red
            $errorCount++
        }
    }
    
    # Summary
    Write-Host "`n" + "="*50 -ForegroundColor Green
    Write-Host "Plugin Deployment Summary:" -ForegroundColor Green
    Write-Host "  Deployed: $deployedCount plugins" -ForegroundColor Green
    Write-Host "  Activated: $activatedCount plugins" -ForegroundColor Green
    Write-Host "  Errors: $errorCount" -ForegroundColor $(if ($errorCount -gt 0) { "Red" } else { "Green" })
    Write-Host "="*50 -ForegroundColor Green
    
    return ($errorCount -eq 0)
}

# Main execution
Deploy-PluginsToContainer -Container $ContainerName -ProfileName $Profile
