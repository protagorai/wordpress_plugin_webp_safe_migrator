# Simple Plugin Control Information

Write-Host "=== PLUGIN INSTALLATION & ACTIVATION CONTROL ===" -ForegroundColor Green

Write-Host "`n1. CONFIGURATION FILE:" -ForegroundColor Cyan
if (Test-Path "bin/config/plugins.yaml") {
    Write-Host "   ✓ bin/config/plugins.yaml - FOUND" -ForegroundColor Green
    Write-Host "   This file controls which plugins are deployed and activated" -ForegroundColor Gray
} else {
    Write-Host "   ✗ bin/config/plugins.yaml - NOT FOUND" -ForegroundColor Red
    Write-Host "   This is why plugins are not being deployed!" -ForegroundColor Red
}

Write-Host "`n2. AVAILABLE PLUGINS:" -ForegroundColor Cyan
if (Test-Path "src") {
    $pluginCount = (Get-ChildItem -Path "src" -Directory).Count
    Write-Host "   Found $pluginCount plugin directories in src/" -ForegroundColor Yellow
    
    Get-ChildItem -Path "src" -Directory | ForEach-Object {
        $phpFiles = Get-ChildItem -Path $_.FullName -Filter "*.php"
        if ($phpFiles.Count -gt 0) {
            Write-Host "   ✓ $($_.Name) - $($phpFiles.Count) PHP files" -ForegroundColor Green
        } else {
            Write-Host "   ! $($_.Name) - No PHP files" -ForegroundColor Yellow
        }
    }
} else {
    Write-Host "   ✗ src/ directory NOT FOUND" -ForegroundColor Red
}

Write-Host "`n3. CONFIGURATION CONTENT:" -ForegroundColor Cyan
if (Test-Path "bin/config/plugins.yaml") {
    Write-Host "   Current deployment configuration:" -ForegroundColor Yellow
    
    # Show key parts of the configuration
    $content = Get-Content "bin/config/plugins.yaml"
    $showingDeployment = $false
    
    foreach ($line in $content) {
        if ($line -match "deployment:") {
            $showingDeployment = $true
            Write-Host "   $line" -ForegroundColor Gray
        } elseif ($showingDeployment -and ($line -match "^\s*development:" -or $line -match "^\s*production:")) {
            Write-Host "   $line" -ForegroundColor Cyan
        } elseif ($showingDeployment -and $line -match "slug:") {
            Write-Host "   $line" -ForegroundColor Green
        } elseif ($showingDeployment -and $line -match "activate:") {
            Write-Host "   $line" -ForegroundColor Yellow
        } elseif ($showingDeployment -and $line -match "^[a-z_]+:" -and -not ($line -match "^\s")) {
            break  # Stop showing at next main section
        }
    }
}

Write-Host "`n4. HOW TO DEPLOY PLUGINS:" -ForegroundColor Cyan
Write-Host "   Method 1 - Automatic during startup:" -ForegroundColor Yellow
Write-Host "     deploy.bat start                    # Deploys plugins per configuration" -ForegroundColor Gray
Write-Host "`n   Method 2 - Manual deployment to running container:" -ForegroundColor Yellow
Write-Host "     deploy.bat plugins deploy           # Deploy to already running container" -ForegroundColor Gray
Write-Host "`n   Method 3 - Test deployment (dry run):" -ForegroundColor Yellow
Write-Host "     powershell -File setup\deploy-plugins-to-container.ps1 -ContainerName webp-migrator-wordpress -Profile development -DryRun" -ForegroundColor Gray

Write-Host "`n5. CHECK CURRENT WORDPRESS PLUGINS:" -ForegroundColor Cyan
Write-Host "   If WordPress is running, check what's installed:" -ForegroundColor Yellow
Write-Host "     podman exec webp-migrator-wordpress wp plugin list --allow-root" -ForegroundColor Gray

Write-Host "`n================================================================" -ForegroundColor Green
Write-Host "CONTROL LOCATION: bin/config/plugins.yaml" -ForegroundColor Green
Write-Host "================================================================" -ForegroundColor Green
