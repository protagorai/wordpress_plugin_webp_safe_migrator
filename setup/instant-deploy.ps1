# WebP Safe Migrator - Instant One-Click Deploy
# Just run this and everything happens automatically!

Write-Host "🚀 WebP Safe Migrator - Instant Deploy" -ForegroundColor Green
Write-Host "Starting complete automated setup..." -ForegroundColor Cyan
Write-Host ""

# Get script directory and run complete setup
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$CompleteScript = Join-Path $ScriptDir "complete-auto-setup.ps1"

if (Test-Path $CompleteScript) {
    & $CompleteScript
    if ($LASTEXITCODE -eq 0) {
        Write-Host ""
        Write-Host "🎉 SUCCESS! WebP Safe Migrator is ready!" -ForegroundColor Green
        Write-Host "The browser should have opened automatically with WordPress ready to use." -ForegroundColor White
    }
} else {
    Write-Host "❌ Setup script not found!" -ForegroundColor Red
}

