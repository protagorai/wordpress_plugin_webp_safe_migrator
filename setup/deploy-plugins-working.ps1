# Working Plugin Deployment Script
# Simple and reliable deployment for multi-plugin system

param(
    [Parameter(Mandatory=$true)]
    [string]$ContainerName,
    [string]$Profile = "development"
)

Write-Host "=== Working Multi-Plugin Deployment ===" -ForegroundColor Green
Write-Host "Container: $ContainerName" -ForegroundColor Yellow
Write-Host "Profile: $Profile" -ForegroundColor Yellow

# Check container exists
$containerCheck = & podman inspect $ContainerName 2>$null
if ($LASTEXITCODE -ne 0) {
    Write-Host "Container '$ContainerName' not found" -ForegroundColor Red
    exit 1
}

# Get plugins to deploy based on profile
$pluginsToActivate = @()

switch ($Profile) {
    "development" {
        $pluginsToActivate = @(
            @{ slug = "okvir-image-safe-migrator"; activate = $true }
            @{ slug = "example-second-plugin"; activate = $true }
        )
    }
    "production" {
        $pluginsToActivate = @(
            @{ slug = "okvir-image-safe-migrator"; activate = $true }
        )
    }
    default {
        $pluginsToActivate = @(
            @{ slug = "okvir-image-safe-migrator"; activate = $true }
        )
    }
}

Write-Host "Deploying plugins for profile: $Profile" -ForegroundColor Cyan
Write-Host "Plugins to process: $($pluginsToActivate.Count)" -ForegroundColor Gray

$deployedCount = 0
$activatedCount = 0

foreach ($plugin in $pluginsToActivate) {
    $sourcePath = "src/$($plugin.slug)"
    $targetPath = "/var/www/html/wp-content/plugins/$($plugin.slug)"
    
    if (Test-Path $sourcePath) {
        Write-Host "Deploying: $($plugin.slug)" -ForegroundColor Cyan
        
        # Remove existing
        & podman exec $ContainerName rm -rf "$targetPath" 2>$null
        
        # Copy plugin
        & podman cp "$sourcePath" "${ContainerName}:$targetPath"
        
        if ($LASTEXITCODE -eq 0) {
            Write-Host "  ✓ Plugin deployed" -ForegroundColor Green
            $deployedCount++
            
            # Fix permissions
            & podman exec $ContainerName chown -R www-data:www-data "$targetPath" 2>$null
            
            # Activate if configured
            if ($plugin.activate) {
                & podman exec $ContainerName wp plugin activate $plugin.slug --allow-root 2>$null
                if ($LASTEXITCODE -eq 0) {
                    Write-Host "  ✓ Plugin activated" -ForegroundColor Green
                    $activatedCount++
                } else {
                    Write-Host "  ! Activation failed" -ForegroundColor Yellow
                }
            } else {
                Write-Host "  ○ Not activated (per configuration)" -ForegroundColor Gray
            }
        } else {
            Write-Host "  ✗ Deployment failed" -ForegroundColor Red
        }
    } else {
        Write-Host "  ✗ Source not found: $sourcePath" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "Deployment Summary:" -ForegroundColor Green
Write-Host "  Deployed: $deployedCount plugins" -ForegroundColor Green
Write-Host "  Activated: $activatedCount plugins" -ForegroundColor Green

if ($deployedCount -eq $pluginsToActivate.Count) {
    Write-Host "Multi-plugin deployment completed successfully!" -ForegroundColor Green
    exit 0
} else {
    Write-Host "Some plugins failed to deploy" -ForegroundColor Yellow
    exit 1
}