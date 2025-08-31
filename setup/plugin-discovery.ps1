# Simple Plugin Discovery and Deployment Script
# Configuration-driven deployment for any number of plugins

param(
    [Parameter(Mandatory=$true)]
    [ValidateSet("list", "status", "deploy", "deploy-to-container")]
    [string]$Action,
    
    [string]$Profile = "development",
    [string]$ContainerName = "",
    [switch]$DryRun = $false,
    [switch]$Force = $false
)

$ErrorActionPreference = "Continue"

Write-Host "=== Plugin Discovery and Deployment ===" -ForegroundColor Green
Write-Host "Action: $Action" -ForegroundColor Yellow
Write-Host "Profile: $Profile" -ForegroundColor Yellow
if ($ContainerName) {
    Write-Host "Container: $ContainerName" -ForegroundColor Yellow
}

function Get-AllPluginsInSrc {
    $plugins = @()
    
    if (-not (Test-Path "src")) {
        Write-Warning "src/ directory not found"
        return $plugins
    }
    
    $pluginDirs = Get-ChildItem -Path "src" -Directory
    Write-Host "Found $($pluginDirs.Count) directories in src/" -ForegroundColor Cyan
    
    foreach ($dir in $pluginDirs) {
        Write-Host "  Checking: $($dir.Name)" -ForegroundColor Gray
        
        $phpFiles = Get-ChildItem -Path $dir.FullName -Filter "*.php" -ErrorAction SilentlyContinue
        if ($phpFiles.Count -gt 0) {
            # Find main plugin file
            $mainFile = $phpFiles | Where-Object { $_.Name -match $dir.Name } | Select-Object -First 1
            if (-not $mainFile) {
                $mainFile = $phpFiles[0]
            }
            
            # Check plugin header
            $fileContent = Get-Content -Path $mainFile.FullName -Head 10 -ErrorAction SilentlyContinue
            $pluginNameLine = $fileContent | Where-Object { $_ -match "Plugin Name:\s*(.+)" } | Select-Object -First 1
            
            if ($pluginNameLine) {
                $pluginName = if ($matches -and $matches[1]) { $matches[1].Trim() } else { $dir.Name }
                
                $plugin = @{
                    slug = $dir.Name
                    name = $pluginName
                    path = $dir.Name
                    main_file = $mainFile.Name
                    source_path = $dir.FullName
                    exists = $true
                    is_self_contained = (Test-Path (Join-Path $dir.FullName "admin")) -or (Test-Path (Join-Path $dir.FullName "includes")) -or ($phpFiles.Count -eq 1)
                }
                
                $plugins += $plugin
                Write-Host "    ✓ Valid plugin: $pluginName" -ForegroundColor Green
            } else {
                Write-Host "    ! Missing plugin header in $($mainFile.Name)" -ForegroundColor Yellow
            }
        } else {
            Write-Host "    ! No PHP files found" -ForegroundColor Yellow
        }
    }
    
    Write-Host "Total valid plugins: $($plugins.Count)" -ForegroundColor Green
    return $plugins
}

function Get-ConfigurationBasedPlugins {
    param([string]$ProfileName)
    
    # For now, return all plugins with basic configuration
    # In a full implementation, this would parse plugins.yaml
    $allPlugins = Get-AllPluginsInSrc
    
    # Apply basic profile logic
    foreach ($plugin in $allPlugins) {
        switch ($ProfileName) {
            "development" {
                $plugin.deploy = $true
                $plugin.activate = ($plugin.slug -eq "okvir-image-safe-migrator")
            }
            "production" {
                $plugin.deploy = ($plugin.slug -eq "okvir-image-safe-migrator")
                $plugin.activate = ($plugin.slug -eq "okvir-image-safe-migrator")
            }
            "testing" {
                $plugin.deploy = $true
                $plugin.activate = $false  # Manual activation in testing
            }
            default {
                $plugin.deploy = $true
                $plugin.activate = ($plugin.slug -eq "okvir-image-safe-migrator")
            }
        }
    }
    
    $selectedPlugins = $allPlugins | Where-Object { $_.deploy }
    Write-Host "Profile '$ProfileName' selects $($selectedPlugins.Count) plugins for deployment" -ForegroundColor Green
    
    return $selectedPlugins
}

function Show-PluginList {
    $plugins = Get-AllPluginsInSrc
    
    Write-Host "`nAvailable Plugins:" -ForegroundColor Green
    Write-Host "==================" -ForegroundColor Green
    
    if ($plugins.Count -eq 0) {
        Write-Host "No plugins found in src/ directory" -ForegroundColor Yellow
        Write-Host "`nTo add plugins:" -ForegroundColor Cyan
        Write-Host "  1. Create directory under src/ (e.g., src/my-new-plugin/)"
        Write-Host "  2. Add main plugin PHP file with WordPress plugin header"
        Write-Host "  3. Add any admin/, includes/, or other folders as needed"
        return
    }
    
    foreach ($plugin in $plugins) {
        Write-Host "`n$($plugin.name)" -ForegroundColor Cyan
        Write-Host "  Slug: $($plugin.slug)"
        Write-Host "  Path: src/$($plugin.path)/"
        Write-Host "  Main File: $($plugin.main_file)"
        Write-Host "  Self-Contained: $(if ($plugin.is_self_contained) { 'Yes' } else { 'No' })" -ForegroundColor $(if ($plugin.is_self_contained) { "Green" } else { "Yellow" })
        
        # Show plugin structure
        $subDirs = Get-ChildItem -Path $plugin.source_path -Directory -ErrorAction SilentlyContinue
        if ($subDirs.Count -gt 0) {
            Write-Host "  Structure:"
            foreach ($subDir in $subDirs) {
                Write-Host "    - $($subDir.Name)/" -ForegroundColor Gray
            }
        }
    }
    
    Write-Host "`nSelf-Containment Summary:" -ForegroundColor Cyan
    $selfContainedCount = ($plugins | Where-Object { $_.is_self_contained }).Count
    Write-Host "  Self-contained plugins: $selfContainedCount / $($plugins.Count)"
    
    if ($selfContainedCount -lt $plugins.Count) {
        Write-Host "`nTo make plugins self-contained:" -ForegroundColor Yellow
        Write-Host "  1. Move any shared dependencies into each plugin folder"
        Write-Host "  2. Ensure each plugin has its own admin/ and includes/ folders if needed"
        Write-Host "  3. Remove dependencies on external shared folders"
    }
}

function Show-PluginStatus {
    $plugins = Get-ConfigurationBasedPlugins -ProfileName $Profile
    
    Write-Host "`nPlugin Status for Profile: $Profile" -ForegroundColor Green
    Write-Host "==========================================" -ForegroundColor Green
    
    if ($plugins.Count -eq 0) {
        Write-Host "No plugins configured for deployment in profile: $Profile" -ForegroundColor Yellow
        return
    }
    
    foreach ($plugin in $plugins) {
        $deployText = if ($plugin.deploy) { "Deploy" } else { "Skip" }
        $activateText = if ($plugin.activate) { "Activate" } else { "Deploy only" }
        $containmentText = if ($plugin.is_self_contained) { "Self-contained" } else { "Needs work" }
        
        Write-Host "`n$($plugin.name) ($($plugin.slug))" -ForegroundColor Cyan
        Write-Host "  Action: $deployText" -ForegroundColor $(if ($plugin.deploy) { "Green" } else { "Gray" })
        Write-Host "  Activation: $activateText" -ForegroundColor $(if ($plugin.activate) { "Green" } else { "Gray" })
        Write-Host "  Containment: $containmentText" -ForegroundColor $(if ($plugin.is_self_contained) { "Green" } else { "Yellow" })
    }
}

# Main execution
switch ($Action) {
    "list" {
        Show-PluginList
    }
    
    "status" {
        Show-PluginStatus
    }
    
    "deploy-to-container" {
        if ([string]::IsNullOrEmpty($ContainerName)) {
            Write-Error "ContainerName parameter is required for deploy-to-container action"
            exit 1
        }
        
        $plugins = Get-ConfigurationBasedPlugins -ProfileName $Profile
        
        if ($plugins.Count -eq 0) {
            Write-Warning "No plugins to deploy for profile: $Profile"
            exit 1
        }
        
        Write-Host "`nDeploying $($plugins.Count) plugins to container: $ContainerName" -ForegroundColor Green
        
        if ($DryRun) {
            Write-Host "[DRY RUN] Would deploy the following plugins:" -ForegroundColor Yellow
            foreach ($plugin in $plugins) {
                $action = if ($plugin.activate) { "Deploy + Activate" } else { "Deploy only" }
                Write-Host "  - $($plugin.name): $action" -ForegroundColor Gray
            }
            exit 0
        }
        
        # Check container exists
        $containerCheck = & podman inspect $ContainerName 2>$null
        if ($LASTEXITCODE -ne 0) {
            Write-Error "Container '$ContainerName' not found"
            exit 1
        }
        
        $successCount = 0
        foreach ($plugin in $plugins) {
            Write-Host "`nDeploying: $($plugin.name)" -ForegroundColor Cyan
            
            $targetPath = "/var/www/html/wp-content/plugins/$($plugin.slug)"
            
            # Remove existing
            & podman exec $ContainerName rm -rf "$targetPath" 2>$null
            
            # Copy plugin
            & podman cp "$($plugin.source_path)" "${ContainerName}:$targetPath"
            
            if ($LASTEXITCODE -eq 0) {
                Write-Host "  ✓ Copied successfully" -ForegroundColor Green
                
                # Fix permissions
                & podman exec $ContainerName chown -R www-data:www-data "$targetPath" 2>$null
                
                # Activate if configured
                if ($plugin.activate) {
                    & podman exec $ContainerName wp plugin activate $plugin.slug --allow-root 2>$null
                    if ($LASTEXITCODE -eq 0) {
                        Write-Host "  ✓ Activated successfully" -ForegroundColor Green
                    } else {
                        Write-Host "  ! Activation failed" -ForegroundColor Yellow
                    }
                }
                
                $successCount++
            } else {
                Write-Host "  ✗ Copy failed" -ForegroundColor Red
            }
        }
        
        Write-Host "`nDeployment completed: $successCount/$($plugins.Count) successful" -ForegroundColor Green
    }
    
    default {
        Write-Host "Unknown action: $Action" -ForegroundColor Red
        exit 1
    }
}

Write-Host "`nPlugin discovery completed successfully!" -ForegroundColor Green
