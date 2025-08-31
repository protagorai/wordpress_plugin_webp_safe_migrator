# Manual Plugin Activation Script
# Use this to manually activate plugins in a running WordPress container

param(
    [string]$PluginSlug = "",
    [string]$ContainerName = "webp-migrator-wordpress"
)

Write-Host "=== Manual Plugin Activation ===" -ForegroundColor Green

if ([string]::IsNullOrEmpty($PluginSlug)) {
    Write-Host "Available plugins to activate:" -ForegroundColor Cyan
    
    Write-Host ""
    Write-Host "Current plugin status in WordPress:" -ForegroundColor Cyan
    & podman exec $ContainerName wp plugin list --allow-root 2>$null
    
    Write-Host ""
    Write-Host "Usage Examples:" -ForegroundColor Yellow
    Write-Host "  deploy.bat plugins activate example-second-plugin"
    Write-Host "  deploy.bat plugins activate okvir-image-safe-migrator"
    Write-Host "  deploy.bat plugins status"
    
    exit 0
}

# Check if container exists
$containerCheck = & podman inspect $ContainerName 2>$null
if ($LASTEXITCODE -ne 0) {
    Write-Host "Container '$ContainerName' not found. Is WordPress running?" -ForegroundColor Red
    Write-Host "Start WordPress first: deploy.bat start" -ForegroundColor Yellow
    exit 1
}

Write-Host "Activating plugin: $PluginSlug" -ForegroundColor Cyan

# Activate the plugin
& podman exec $ContainerName wp plugin activate $PluginSlug --allow-root 2>$null

if ($LASTEXITCODE -eq 0) {
    Write-Host "Plugin '$PluginSlug' activated successfully!" -ForegroundColor Green
} else {
    Write-Host "Plugin activation failed. Possible reasons:" -ForegroundColor Red
    Write-Host "  - Plugin not deployed yet" -ForegroundColor Yellow
    Write-Host "  - Plugin has activation errors" -ForegroundColor Yellow
    Write-Host "  - Container not running" -ForegroundColor Yellow
    
    Write-Host ""
    Write-Host "Try deploying first: deploy.bat start" -ForegroundColor Cyan
}

Write-Host ""
Write-Host "Updated plugin status:" -ForegroundColor Cyan
& podman exec $ContainerName wp plugin list --allow-root 2>$null