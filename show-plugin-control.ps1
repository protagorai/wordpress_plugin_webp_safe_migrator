# Show Plugin Installation and Activation Control
# This script shows where the configuration is and how it controls plugin deployment

Write-Host "=== Plugin Installation & Activation Control ===" -ForegroundColor Green

Write-Host "`n1. CONFIGURATION LOCATION:" -ForegroundColor Cyan
Write-Host "   File: bin/config/plugins.yaml" -ForegroundColor Yellow
Write-Host "   This file controls which plugins get deployed and activated"

if (Test-Path "bin/config/plugins.yaml") {
    Write-Host "   Status: ✓ Configuration file found" -ForegroundColor Green
    
    Write-Host "`n2. CURRENT CONFIGURATION:" -ForegroundColor Cyan
    
    $content = Get-Content "bin/config/plugins.yaml" -Raw
    
    # Show deployment section
    Write-Host "`n   DEPLOYMENT PROFILES:" -ForegroundColor Yellow
    $lines = $content -split "`n"
    $inDeployment = $false
    $currentProfile = ""
    
    foreach ($line in $lines) {
        $line = $line.Trim()
        
        if ($line -match "^deployment:") {
            $inDeployment = $true
            continue
        }
        
        if ($inDeployment -and $line -match "^(\w+):") {
            $currentProfile = $matches[1]
            Write-Host "`n   Profile: $currentProfile" -ForegroundColor Cyan
            continue
        }
        
        if ($inDeployment -and $line -match "^\s*-\s+slug:\s*[""']?([^""']*)[""']?") {
            $pluginSlug = $matches[1]
            Write-Host "     Plugin: $pluginSlug" -ForegroundColor Gray
            continue
        }
        
        if ($inDeployment -and $line -match "^\s+activate:\s*(\w+)") {
            $activate = $matches[1]
            $color = if ($activate -eq "true") { "Green" } else { "Yellow" }
            Write-Host "       Activate: $activate" -ForegroundColor $color
            continue
        }
    }
} else {
    Write-Host "   Status: ✗ Configuration file NOT found" -ForegroundColor Red
    Write-Host "   This means plugins won't be deployed automatically!"
}

Write-Host "`n3. AVAILABLE PLUGINS IN src/:" -ForegroundColor Cyan

if (Test-Path "src") {
    $pluginDirs = Get-ChildItem -Path "src" -Directory
    Write-Host "   Found $($pluginDirs.Count) plugin directories:" -ForegroundColor Yellow
    
    foreach ($dir in $pluginDirs) {
        $phpFiles = Get-ChildItem -Path $dir.FullName -Filter "*.php"
        if ($phpFiles.Count -gt 0) {
            Write-Host "   ✓ $($dir.Name)" -ForegroundColor Green
            
            # Check if configured for deployment
            $isConfigured = $content -match $dir.Name
            if ($isConfigured) {
                Write-Host "     Status: Configured for deployment" -ForegroundColor Green
            } else {
                Write-Host "     Status: NOT configured for deployment" -ForegroundColor Yellow
            }
        } else {
            Write-Host "   ! $($dir.Name) - No PHP files" -ForegroundColor Red
        }
    }
} else {
    Write-Host "   Status: ✗ src/ directory NOT found" -ForegroundColor Red
}

Write-Host "`n4. HOW TO CONTROL PLUGIN DEPLOYMENT:" -ForegroundColor Cyan
Write-Host "   Edit: bin/config/plugins.yaml" -ForegroundColor Yellow
Write-Host "   Example configuration:" -ForegroundColor Gray
Write-Host @"
   
   deployment:
     development:
       plugins:
         - slug: "okvir-image-safe-migrator"
           activate: true
         - slug: "example-second-plugin"
           activate: false
           
     production:
       plugins:
         - slug: "okvir-image-safe-migrator"
           activate: true
"@ -ForegroundColor Gray

Write-Host "`n5. DEPLOYMENT COMMANDS:" -ForegroundColor Cyan
Write-Host "   deploy.bat start                    # Deploy with development profile" -ForegroundColor Yellow
Write-Host "   deploy.bat plugins deploy           # Deploy plugins to running container" -ForegroundColor Yellow
Write-Host "   deploy.bat plugins list             # List available plugins" -ForegroundColor Yellow

Write-Host "`n6. TEST PLUGIN DEPLOYMENT:" -ForegroundColor Cyan
Write-Host "   Test deployment:" -ForegroundColor Yellow
Write-Host "     powershell -File setup\deploy-plugins-to-container.ps1 -ContainerName webp-migrator-wordpress -Profile development -DryRun" -ForegroundColor Gray

Write-Host "`n================================================================" -ForegroundColor Green
Write-Host "SUMMARY: Plugin deployment is controlled by bin/config/plugins.yaml" -ForegroundColor Green
Write-Host "================================================================" -ForegroundColor Green
