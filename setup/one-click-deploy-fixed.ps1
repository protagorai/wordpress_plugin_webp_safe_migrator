# WebP Safe Migrator - One-Click Deployment
# The simplest possible deployment - just run and go!

param(
    [switch]$Help
)

if ($Help) {
    Write-Host "🚀 WebP Safe Migrator - One-Click Deployment" -ForegroundColor Green
    Write-Host ""
    Write-Host "This script automatically:"
    Write-Host "  ✅ Downloads and starts all containers"
    Write-Host "  ✅ Installs WordPress with optimal settings"
    Write-Host "  ✅ Activates the WebP Safe Migrator plugin"
    Write-Host "  ✅ Creates sample content for testing"
    Write-Host "  ✅ Opens WordPress in your browser"
    Write-Host ""
    Write-Host "Usage:"
    Write-Host "  .\one-click-deploy.ps1        # Deploy everything automatically"
    Write-Host "  .\one-click-deploy.ps1 -Help  # Show this help"
    Write-Host ""
    Write-Host "Access URLs after deployment:"
    Write-Host "  🌐 WordPress: http://localhost:8080"
    Write-Host "  🔧 Admin: http://localhost:8080/wp-admin"
    Write-Host "  🗄️ Database: http://localhost:8081 (phpMyAdmin)"
    Write-Host ""
    Write-Host "Default credentials:"
    Write-Host "  👤 Username: admin"
    Write-Host "  🔑 Password: admin123!"
    exit 0
}

Write-Host ""
Write-Host "🚀 WebP Safe Migrator - One-Click Deployment" -ForegroundColor Green
Write-Host "=================================================" -ForegroundColor Green
Write-Host ""
Write-Host "Starting automated deployment..." -ForegroundColor Cyan
Write-Host "This will take 2-3 minutes to complete." -ForegroundColor Yellow
Write-Host ""

# Get script directory
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path

# Check if deployment script exists
$DeployScript = Join-Path $ScriptDir "webp-migrator-deploy.ps1"

if (Test-Path $DeployScript) {
    Write-Host "🔄 Executing deployment script..." -ForegroundColor Cyan
    
    try {
        # Execute the main deployment script with clean start
        & $DeployScript -CleanStart
        
        if ($LASTEXITCODE -eq 0) {
            Write-Host ""
            Write-Host "🎉 SUCCESS! WebP Safe Migrator is ready to use!" -ForegroundColor Green
            Write-Host ""
            Write-Host "🎯 Next steps:" -ForegroundColor Cyan
            Write-Host "1. WordPress should open in your browser automatically"
            Write-Host "2. Go to Media → WebP Migrator to test the plugin"
            Write-Host "3. Upload some images and try the conversion features"
        } else {
            Write-Host ""
            Write-Host "❌ Deployment failed. Please check the error messages above." -ForegroundColor Red
            exit 1
        }
    }
    catch {
        Write-Host ""
        Write-Host "❌ Deployment failed with error:" -ForegroundColor Red
        Write-Host $_.Exception.Message -ForegroundColor Red
        exit 1
    }
} else {
    Write-Host "❌ Deployment script not found: $DeployScript" -ForegroundColor Red
    Write-Host "Make sure you are running this from the setup directory." -ForegroundColor Yellow
    exit 1
}
