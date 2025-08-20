# WebP Migrator - One-Command WordPress Setup
# Downloads, installs, and configures everything automatically

param(
    [string]$InstallPath = "C:\webp-migrator-test",
    [switch]$StartAfterInstall = $true
)

Write-Host "🚀 WebP Migrator - One-Command Setup" -ForegroundColor Green
Write-Host "This will create a complete WordPress test environment at: $InstallPath" -ForegroundColor Yellow
Write-Host ""

# Confirm installation
$confirm = Read-Host "Continue? (Y/n)"
if ($confirm -eq 'n' -or $confirm -eq 'N') {
    Write-Host "Installation cancelled." -ForegroundColor Yellow
    exit
}

try {
    # Run the full automated installation
    Write-Host "🔄 Running automated installation..." -ForegroundColor Cyan
    
    $scriptPath = Join-Path $PSScriptRoot "install-wordpress-automated.ps1"
    
    & $scriptPath -InstallPath $InstallPath -AutoInstall -StartServices:$StartAfterInstall
    
    if ($LASTEXITCODE -eq 0) {
        Write-Host ""
        Write-Host "🎉 SUCCESS! Your WebP Migrator test environment is ready!" -ForegroundColor Green
        Write-Host ""
        Write-Host "📍 Quick Access:" -ForegroundColor Cyan
        Write-Host "   Website: http://localhost:8080" -ForegroundColor White
        Write-Host "   Admin:   http://localhost:8080/wp-admin" -ForegroundColor White
        Write-Host "   Plugin:  Media → WebP Migrator" -ForegroundColor White
        Write-Host ""
        Write-Host "🔑 Login Credentials:" -ForegroundColor Cyan
        Write-Host "   Username: admin" -ForegroundColor White
        Write-Host "   Password: admin123!" -ForegroundColor White
        Write-Host ""
        Write-Host "🛠️ Service Management:" -ForegroundColor Cyan
        Write-Host "   Start:  $InstallPath\start-services.bat" -ForegroundColor White
        Write-Host "   Stop:   $InstallPath\stop-services.bat" -ForegroundColor White
        Write-Host ""
        
        if ($StartAfterInstall) {
            Write-Host "🌐 Opening WordPress in your browser..." -ForegroundColor Cyan
            Start-Sleep -Seconds 3
            Start-Process "http://localhost:8080"
        }
    }
    
} catch {
    Write-Host "❌ Installation failed: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host ""
    Write-Host "🔧 Troubleshooting:" -ForegroundColor Yellow
    Write-Host "1. Run PowerShell as Administrator" -ForegroundColor White
    Write-Host "2. Check that ports 8080, 3306, 9000 are available" -ForegroundColor White
    Write-Host "3. Temporarily disable antivirus/Windows Defender" -ForegroundColor White
    Write-Host "4. Try manual installation with install-wordpress.ps1" -ForegroundColor White
}
