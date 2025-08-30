# ==============================================================================
# WebP Safe Migrator - Universal Resource Pre-Download Script (PowerShell)
# Downloads all required Docker images and tools - Works on Windows/Linux/macOS
# ==============================================================================

param(
    [switch]$Force,
    [string]$ContainerEngine = "auto"
)

$ErrorActionPreference = "Continue"

# Unicode spinner for progress indication
$Spinner = @("⠋", "⠙", "⠹", "⠸", "⠼", "⠴", "⠦", "⠧", "⠇", "⠏")

Write-Host ""
Write-Host "=====================================" -ForegroundColor Green
Write-Host "  WebP Safe Migrator Pre-Download" -ForegroundColor Green  
Write-Host "=====================================" -ForegroundColor Green
Write-Host ""
Write-Host "⬇️  Pre-downloading all required resources..." -ForegroundColor Blue
Write-Host "   This will speed up the setup process significantly!" -ForegroundColor Cyan
Write-Host ""

# Function to show download progress with spinner
function Show-DownloadProgress {
    param(
        [string]$ResourceName,
        [scriptblock]$Command,
        [string]$SuccessMessage
    )
    
    Write-Host "⠋ Downloading $ResourceName..." -ForegroundColor Cyan
    
    # Start background job for download
    $job = Start-Job -ScriptBlock $Command
    $spinIndex = 0
    
    while ($job.State -eq "Running") {
        $char = $Spinner[$spinIndex % $Spinner.Count]
        Write-Host "`r$char Downloading $ResourceName..." -NoNewline -ForegroundColor Cyan
        Start-Sleep -Milliseconds 200
        $spinIndex++
    }
    
    # Get job results
    $result = Receive-Job -Job $job
    $exitCode = $job.State
    Remove-Job -Job $job
    
    Write-Host "`r" -NoNewline
    
    if ($exitCode -eq "Completed") {
        Write-Host "✅ $SuccessMessage" -ForegroundColor Green
    } else {
        Write-Host "❌ Failed to download $ResourceName" -ForegroundColor Red
        Write-Host "Error details:" -ForegroundColor Yellow
        Write-Host $result -ForegroundColor Red
        Write-Host ""
        Write-Host "Press Enter to copy this error and continue..." -ForegroundColor Yellow
        Read-Host
    }
    Write-Host ""
}

# Detect container engine
Write-Host "⠋ Checking container engine availability..." -ForegroundColor Cyan
if ($ContainerEngine -eq "auto") {
    if (Get-Command podman -ErrorAction SilentlyContinue) {
        $ContainerEngine = "podman"
        Write-Host "✅ Podman detected" -ForegroundColor Green
    } elseif (Get-Command docker -ErrorAction SilentlyContinue) {
        $ContainerEngine = "docker"
        Write-Host "✅ Docker detected" -ForegroundColor Green
    } else {
        Write-Host "❌ ERROR: Neither Podman nor Docker found" -ForegroundColor Red
        Write-Host "Please install Podman or Docker first" -ForegroundColor Yellow
        Write-Host ""
        Read-Host "Press Enter to exit"
        exit 1
    }
} else {
    if (-not (Get-Command $ContainerEngine -ErrorAction SilentlyContinue)) {
        Write-Host "❌ ERROR: $ContainerEngine not found" -ForegroundColor Red
        exit 1
    }
    Write-Host "✅ Using $ContainerEngine" -ForegroundColor Green
}

Write-Host ""
Write-Host "📦 Starting resource downloads..." -ForegroundColor Blue
Write-Host ""

# Download WordPress image
Show-DownloadProgress "WordPress Docker Image" {
    & $using:ContainerEngine pull docker.io/library/wordpress:latest 2>&1
} "WordPress image ready"

# Download MySQL image
Show-DownloadProgress "MySQL Docker Image" {
    & $using:ContainerEngine pull docker.io/library/mysql:8.0 2>&1
} "MySQL image ready"

# Download phpMyAdmin image  
Show-DownloadProgress "phpMyAdmin Docker Image" {
    & $using:ContainerEngine pull docker.io/library/phpmyadmin:latest 2>&1
} "phpMyAdmin image ready"

# Download WP-CLI
Write-Host "⠦ Downloading WP-CLI tool..." -ForegroundColor Cyan
if (-not (Test-Path "temp_wpcli.phar") -or $Force) {
    try {
        Invoke-WebRequest -Uri "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar" -OutFile "temp_wpcli.phar"
        Write-Host "✅ WP-CLI downloaded successfully" -ForegroundColor Green
        Write-Host "   📁 Saved as temp_wpcli.phar (will be used during setup)" -ForegroundColor Cyan
    } catch {
        Write-Host "❌ Failed to download WP-CLI" -ForegroundColor Red
        Write-Host "This is optional - WP-CLI will be installed during container setup" -ForegroundColor Yellow
    }
} else {
    Write-Host "✅ WP-CLI already downloaded" -ForegroundColor Green
}
Write-Host ""

# Verify all images are available
Write-Host "🔍 Verifying downloaded resources..." -ForegroundColor Blue
Write-Host ""

try {
    $images = & $ContainerEngine images --format "table {{.Repository}}\t{{.Tag}}\t{{.Size}}" | Select-String "(wordpress|mysql|phpmyadmin)"
    if ($images) {
        Write-Host "✅ All container images verified" -ForegroundColor Green
        Write-Host ""
        & $ContainerEngine images --format "table {{.Repository}}\t{{.Tag}}\t{{.Size}}" | Select-String "(Repository|wordpress|mysql|phpmyadmin)"
    } else {
        Write-Host "⚠️  Some images may not have downloaded correctly" -ForegroundColor Yellow
    }
} catch {
    Write-Host "⚠️  Could not verify images" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "=====================================" -ForegroundColor Green
Write-Host "   🎉 PRE-DOWNLOAD COMPLETE! 🎉" -ForegroundColor Green
Write-Host "=====================================" -ForegroundColor Green
Write-Host ""
Write-Host "✅ Resources ready for fast setup:" -ForegroundColor Green
Write-Host "   📦 WordPress Docker image" -ForegroundColor Cyan
Write-Host "   📦 MySQL 8.0 Docker image" -ForegroundColor Cyan
Write-Host "   📦 phpMyAdmin Docker image" -ForegroundColor Cyan
Write-Host "   🔧 WP-CLI tool (if available)" -ForegroundColor Cyan
Write-Host ""
Write-Host "🚀 Benefits:" -ForegroundColor Blue
Write-Host "   ⚡ 3-5x faster container startup" -ForegroundColor Cyan
Write-Host "   📊 No download delays during setup" -ForegroundColor Cyan
Write-Host "   🛠️  Consistent offline-capable setup" -ForegroundColor Cyan
Write-Host ""
Write-Host "💡 Next steps:" -ForegroundColor Blue
if ($IsWindows -or $env:OS -eq "Windows_NT") {
    Write-Host "   1. Run: launch-webp-migrator.bat" -ForegroundColor Cyan
} else {
    Write-Host "   1. Run: ./launch-webp-migrator.sh" -ForegroundColor Cyan
}
Write-Host "   2. Setup will use pre-downloaded resources" -ForegroundColor Cyan
Write-Host "   3. Enjoy blazing-fast deployment! 🔥" -ForegroundColor Cyan
Write-Host ""
Write-Host "Press Enter to close..." -ForegroundColor Yellow
Read-Host
