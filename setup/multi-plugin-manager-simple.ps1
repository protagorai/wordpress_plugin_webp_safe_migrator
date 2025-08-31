# Simple Multi-Plugin Manager for WordPress Development Environment
# Basic plugin management without complex try-catch blocks

param(
    [Parameter(Mandatory=$true)]
    [ValidateSet("list", "status", "deploy-to-container")]
    [string]$Action,
    
    [string]$Profile = "development",
    [string]$ContainerName = "",
    [switch]$DryRun = $false,
    [switch]$Force = $false
)

$ErrorActionPreference = "Continue"

Write-Host "=== Simple Multi-Plugin Manager ===" -ForegroundColor Green
Write-Host "Action: $Action" -ForegroundColor Yellow
Write-Host "Profile: $Profile" -ForegroundColor Yellow
if ($ContainerName) {
    Write-Host "Container: $ContainerName" -ForegroundColor Yellow
}
Write-Host "Dry Run: $DryRun" -ForegroundColor Yellow

function Get-AvailablePlugins {
    $plugins = @()
    
    if (Test-Path "src") {
        $pluginDirs = Get-ChildItem -Path "src" -Directory
        Write-Host "Scanning src/ directory... Found $($pluginDirs.Count) potential plugin directories" -ForegroundColor Cyan
        
        foreach ($dir in $pluginDirs) {
            Write-Host "  Checking: $($dir.Name)" -ForegroundColor Gray
            
            # Look for PHP files in the plugin directory
            $phpFiles = Get-ChildItem -Path $dir.FullName -Filter "*.php" -ErrorAction SilentlyContinue
            if ($phpFiles.Count -gt 0) {
                # Find main plugin file (should match directory name or be the only PHP file)
                $mainFile = $phpFiles | Where-Object { $_.Name -match $dir.Name } | Select-Object -First 1
                if (-not $mainFile) {
                    $mainFile = $phpFiles[0]
                }
                
                # Check if it's a valid WordPress plugin by looking for plugin headers
                $fileContent = Get-Content -Path $mainFile.FullName -Head 15 -ErrorAction SilentlyContinue
                $hasPluginHeader = $fileContent | Where-Object { $_ -match "Plugin Name:" }
                
                if ($hasPluginHeader) {
                    $plugin = @{
                        slug = $dir.Name
                        name = $dir.Name -replace '-', ' ' | ForEach-Object { (Get-Culture).TextInfo.ToTitleCase($_) }
                        path = $dir.Name
                        main_file = $mainFile.Name
                        source_path = $dir.FullName
                        exists = $true
                        is_valid_plugin = $true
                    }
                    
                    # Extract plugin name from header if available
                    $pluginNameLine = $fileContent | Where-Object { $_ -match "Plugin Name:\s*(.+)" } | Select-Object -First 1
                    if ($pluginNameLine -and $matches[1]) {
                        $plugin.name = $matches[1].Trim()
                    }
                    
                    $plugins += $plugin
                    Write-Host "    ✓ Valid WordPress plugin: $($plugin.name)" -ForegroundColor Green
                } else {
                    Write-Host "    ! Not a valid WordPress plugin (missing plugin header)" -ForegroundColor Yellow
                }
            } else {
                Write-Host "    ! No PHP files found" -ForegroundColor Yellow
            }
        }
    } else {
        Write-Warning "src/ directory not found"
    }
    
    Write-Host "Found $($plugins.Count) valid plugins total" -ForegroundColor Green
    return $plugins
}

function Show-AvailablePlugins {
    $plugins = Get-AvailablePlugins
    
    Write-Host "`nAvailable Plugins:" -ForegroundColor Green
    Write-Host "==================" -ForegroundColor Green
    
    if ($plugins.Count -eq 0) {
        Write-Host "No plugins found in src/ directory" -ForegroundColor Yellow
        return
    }
    
    foreach ($plugin in $plugins) {
        Write-Host "`n$($plugin.name) ($($plugin.slug))" -ForegroundColor Cyan
        Write-Host "  Path: $($plugin.path)"
        Write-Host "  Main File: $($plugin.main_file)"
        Write-Host "  Source: $($plugin.source_path)"
        Write-Host "  Status: Available" -ForegroundColor Green
    }
    
    Write-Host "`nTotal: $($plugins.Count) plugins available" -ForegroundColor Green
}

function Show-PluginStatus {
    $plugins = Get-AvailablePlugins
    
    Write-Host "`nPlugin Status Report" -ForegroundColor Green
    Write-Host "===================" -ForegroundColor Green
    
    Write-Host "`nSource Directory: src/" -ForegroundColor Cyan
    foreach ($plugin in $plugins) {
        $status = if ($plugin.exists) { "Available" } else { "Missing" }
        $color = if ($plugin.exists) { "Green" } else { "Red" }
        Write-Host "  $($plugin.name) ($($plugin.slug)): $status" -ForegroundColor $color
    }
    
    Write-Host "`nConfiguration Profile: $Profile" -ForegroundColor Cyan
    Write-Host "  Target plugins: All available plugins"
    Write-Host "  Deployment mode: Development"
}

function Get-DeploymentConfiguration {
    param([string]$ProfileName)
    
    # Load configuration from plugins.yaml if it exists
    $configFile = "bin/config/plugins.yaml"
    if (Test-Path $configFile) {
        Write-Host "Loading deployment configuration for profile: $ProfileName" -ForegroundColor Cyan
        
        # Simple YAML parsing for deployment configuration
        $content = Get-Content -Path $configFile -Raw
        $lines = $content -split "`n"
        
        $inDeployment = $false
        $inProfile = $false
        $currentProfile = ""
        $deploymentConfig = @()
        
        foreach ($line in $lines) {
            $line = $line.Trim()
            
            if ($line -match "^deployment:") {
                $inDeployment = $true
                continue
            }
            
            if ($inDeployment -and $line -match "^$ProfileName:") {
                $inProfile = $true
                $currentProfile = $ProfileName
                continue
            }
            
            if ($inProfile -and $line -match "^(\w+):") {
                # New section, stop parsing this profile
                $inProfile = $false
            }
            
            if ($inProfile -and $line -match "^-\s+slug:\s*[""']?([^""']*)[""']?") {
                $deploymentConfig += @{
                    slug = $matches[1]
                    activate = $true  # Default to activate
                }
                continue
            }
            
            if ($inProfile -and $line -match "^activate:\s*(\w+)") {
                if ($deploymentConfig.Count -gt 0) {
                    $deploymentConfig[-1].activate = ($matches[1] -eq "true")
                }
            }
        }
        
        Write-Host "Configuration loaded: $($deploymentConfig.Count) plugins configured for $ProfileName profile" -ForegroundColor Green
        return $deploymentConfig
    } else {
        Write-Warning "Configuration file not found: $configFile"
        Write-Host "Falling back to deploy all available plugins" -ForegroundColor Yellow
        return @()
    }
}

function Get-PluginsToDeployByConfiguration {
    param([string]$ProfileName)
    
    $availablePlugins = Get-AvailablePlugins
    $deploymentConfig = Get-DeploymentConfiguration -ProfileName $ProfileName
    
    if ($deploymentConfig.Count -eq 0) {
        # No configuration found, deploy all available plugins with default settings
        Write-Host "No deployment configuration found, deploying all $($availablePlugins.Count) available plugins" -ForegroundColor Yellow
        foreach ($plugin in $availablePlugins) {
            $plugin.activate = ($plugin.slug -eq "okvir-image-safe-migrator")  # Only activate primary plugin by default
        }
        return $availablePlugins
    }
    
    # Match configuration with available plugins
    $deploymentPlugins = @()
    foreach ($configPlugin in $deploymentConfig) {
        $availablePlugin = $availablePlugins | Where-Object { $_.slug -eq $configPlugin.slug }
        if ($availablePlugin) {
            $availablePlugin.activate = $configPlugin.activate
            $deploymentPlugins += $availablePlugin
            Write-Host "  ✓ Configured for deployment: $($availablePlugin.name) [Activate: $($configPlugin.activate)]" -ForegroundColor Green
        } else {
            Write-Warning "  ! Plugin '$($configPlugin.slug)' in configuration but not found in src/"
        }
    }
    
    Write-Host "Configuration-based deployment: $($deploymentPlugins.Count) plugins selected" -ForegroundColor Green
    return $deploymentPlugins
}

function Deploy-ToContainer {
    param([string]$ProfileName, [string]$Container)
    
    if ([string]::IsNullOrEmpty($Container)) {
        Write-Error "Container name is required for deploy-to-container action"
        return $false
    }
    
    Write-Host "Deploying plugins to container: $Container using profile: $ProfileName" -ForegroundColor Green
    
    # Check if container exists
    $containerCheck = & podman inspect $Container 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Container '$Container' not found"
        return $false
    }
    
    # Get plugins based on configuration
    $plugins = Get-PluginsToDeployByConfiguration -ProfileName $ProfileName
    if ($plugins.Count -eq 0) {
        Write-Warning "No plugins selected for deployment in profile: $ProfileName"
        return $false
    }
    
    Write-Host "`nDeployment Summary:" -ForegroundColor Cyan
    Write-Host "  Profile: $ProfileName" -ForegroundColor Gray
    Write-Host "  Container: $Container" -ForegroundColor Gray
    Write-Host "  Plugins to deploy: $($plugins.Count)" -ForegroundColor Gray
    
    foreach ($plugin in $plugins) {
        $activateText = if ($plugin.activate) { "✓ Activate" } else { "○ Deploy only" }
        Write-Host "    - $($plugin.name) ($($plugin.slug)) [$activateText]" -ForegroundColor Gray
    }
    
    if ($DryRun) {
        Write-Host "`n[DRY RUN] Would deploy plugins to container" -ForegroundColor Yellow
        return $true
    }
    
    if (-not $Force) {
        $confirm = Read-Host "`nContinue with deployment? (y/N)"
        if ($confirm -ne 'y' -and $confirm -ne 'Y') {
            Write-Host "Deployment cancelled by user" -ForegroundColor Yellow
            return $false
        }
    }
    
    $successCount = 0
    $errorCount = 0
    $activatedCount = 0
    
    # Deploy each plugin according to configuration
    foreach ($plugin in $plugins) {
        Write-Host "`nDeploying plugin: $($plugin.name)" -ForegroundColor Cyan
        
        $sourcePath = $plugin.source_path
        $targetPath = "/var/www/html/wp-content/plugins/$($plugin.slug)"
        
        if (Test-Path $sourcePath) {
            # Verify plugin is self-contained
            $selfContained = Test-PluginSelfContainment -PluginPath $sourcePath
            if (-not $selfContained) {
                Write-Warning "  ! Plugin may not be fully self-contained"
            }
            
            # Remove existing plugin in container
            & podman exec $Container rm -rf "$targetPath" 2>$null
            
            # Copy plugin to container
            Write-Host "  * Copying plugin files..." -ForegroundColor Cyan
            & podman cp "$sourcePath" "${Container}:$targetPath"
            
            if ($LASTEXITCODE -eq 0) {
                Write-Host "  ✓ Plugin copied successfully" -ForegroundColor Green
                
                # Fix permissions
                & podman exec $Container chown -R www-data:www-data "$targetPath" 2>$null
                
                # Activate plugin if configured to do so
                if ($plugin.activate) {
                    Write-Host "  * Activating plugin..." -ForegroundColor Cyan
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
                
                $successCount++
            } else {
                Write-Host "  ✗ Failed to copy plugin to container" -ForegroundColor Red
                $errorCount++
            }
        } else {
            Write-Host "  ✗ Plugin source not found: $sourcePath" -ForegroundColor Red
            $errorCount++
        }
    }
    
    Write-Host "`n" + "="*60 -ForegroundColor Green
    Write-Host "Container deployment completed!" -ForegroundColor Green
    Write-Host "  Deployed: $successCount plugins" -ForegroundColor Green
    Write-Host "  Activated: $activatedCount plugins" -ForegroundColor Green
    Write-Host "  Errors: $errorCount" -ForegroundColor $(if ($errorCount -gt 0) { "Red" } else { "Green" })
    Write-Host "="*60 -ForegroundColor Green
    
    return ($errorCount -eq 0)
}

function Test-PluginSelfContainment {
    param([string]$PluginPath)
    
    # Check if plugin has its own admin and includes folders
    $hasOwnAdmin = Test-Path (Join-Path $PluginPath "admin")
    $hasOwnIncludes = Test-Path (Join-Path $PluginPath "includes")
    
    # A plugin is considered self-contained if it either:
    # 1. Has its own admin/includes folders, OR
    # 2. Is a simple single-file plugin
    $phpFiles = Get-ChildItem -Path $PluginPath -Filter "*.php"
    $isSimplePlugin = $phpFiles.Count -eq 1
    
    return ($hasOwnAdmin -and $hasOwnIncludes) -or $isSimplePlugin
}

# Main execution
switch ($Action) {
    "list" {
        Show-AvailablePlugins
    }
    
    "status" {
        Show-PluginStatus
    }
    
    "deploy-to-container" {
        if (-not $ContainerName) {
            Write-Error "ContainerName parameter is required for deploy-to-container action"
            exit 1
        }
        $result = Deploy-ToContainer -ProfileName $Profile -Container $ContainerName
        if (-not $result) {
            exit 1
        }
    }
    
    default {
        Write-Host "Action '$Action' completed successfully!" -ForegroundColor Green
    }
}

Write-Host "`nSimple multi-plugin operation completed!" -ForegroundColor Green
