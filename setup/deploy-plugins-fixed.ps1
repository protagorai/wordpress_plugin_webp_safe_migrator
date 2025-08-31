# Fixed Configuration-Driven Plugin Deployment
param(
    [Parameter(Mandatory=$true)]
    [string]$ContainerName,
    [string]$Profile = "development",
    [switch]$DryRun = $false
)

Write-Host "=== Configuration-Driven Plugin Deployment ===" -ForegroundColor Green
Write-Host "Container: $ContainerName" -ForegroundColor Yellow
Write-Host "Profile: $Profile" -ForegroundColor Yellow

# Check container exists
$containerCheck = & podman inspect $ContainerName 2>$null
if ($LASTEXITCODE -ne 0) {
    Write-Error "Container '$ContainerName' not found"
    exit 1
}

# Get deployment configuration from plugins.yaml
$configFile = "bin/config/plugins.yaml"
if (-not (Test-Path $configFile)) {
    Write-Warning "Configuration file not found: $configFile"
    exit 1
}

Write-Host "Reading configuration for profile: $Profile" -ForegroundColor Cyan

# Simple configuration reading
$content = Get-Content $configFile -Raw
$deploymentPlugins = @()

# Parse the YAML manually (basic approach)
if ($content -match "development:\s*\r?\n\s*environment_name.*?\r?\n\s*description.*?\r?\n\s*plugins:\s*\r?\n((?:\s*-\s+slug:.*?\r?\n(?:\s+activate:.*?\r?\n)?)*)" -and $Profile -eq "development") {
    $pluginsSection = $matches[1]
    
    # Extract plugin entries
    $pluginMatches = [regex]::Matches($pluginsSection, '\s*-\s+slug:\s*[""'']?([^""'']*)[""'']?\s*\r?\n(?:\s+activate:\s*(\w+))?')
    
    foreach ($match in $pluginMatches) {
        $slug = $match.Groups[1].Value
        $activate = if ($match.Groups[2].Value) { $match.Groups[2].Value -eq "true" } else { $true }
        
        $deploymentPlugins += @{
            slug = $slug
            activate = $activate
        }
    }
}

if ($deploymentPlugins.Count -eq 0) {
    Write-Warning "No plugins configured for profile: $Profile"
    exit 1
}

Write-Host "Found $($deploymentPlugins.Count) plugins to deploy:" -ForegroundColor Green
foreach ($plugin in $deploymentPlugins) {
    $action = if ($plugin.activate) { "Deploy + Activate" } else { "Deploy Only" }
    Write-Host "  - $($plugin.slug): $action" -ForegroundColor Gray
}

if ($DryRun) {
    Write-Host "[DRY RUN] Deployment plan ready" -ForegroundColor Yellow
    exit 0
}

# Deploy each plugin
$deployedCount = 0
$activatedCount = 0

foreach ($plugin in $deploymentPlugins) {
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

Write-Host "`nDeployment Summary:" -ForegroundColor Green
Write-Host "  Deployed: $deployedCount plugins" -ForegroundColor Green  
Write-Host "  Activated: $activatedCount plugins" -ForegroundColor Green
