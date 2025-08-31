# Very Simple Plugin Discovery
param([string]$Action = "list")

Write-Host "=== Simple Plugin Discovery ===" -ForegroundColor Green

if (Test-Path "src") {
    $pluginDirs = Get-ChildItem -Path "src" -Directory
    Write-Host "Found $($pluginDirs.Count) plugin directories:" -ForegroundColor Cyan
    
    foreach ($dir in $pluginDirs) {
        $phpFiles = Get-ChildItem -Path $dir.FullName -Filter "*.php"
        if ($phpFiles.Count -gt 0) {
            Write-Host "  ✓ $($dir.Name) - $($phpFiles.Count) PHP files" -ForegroundColor Green
            
            # Check self-containment
            $hasAdmin = Test-Path (Join-Path $dir.FullName "admin")
            $hasIncludes = Test-Path (Join-Path $dir.FullName "includes")
            
            if ($hasAdmin -or $hasIncludes) {
                Write-Host "    Self-contained: Yes (has own admin/includes)" -ForegroundColor Green
            } else {
                Write-Host "    Self-contained: Simple plugin (single file)" -ForegroundColor Green
            }
        } else {
            Write-Host "  ! $($dir.Name) - No PHP files" -ForegroundColor Yellow
        }
    }
} else {
    Write-Host "src/ directory not found" -ForegroundColor Red
}

Write-Host "`nPlugin discovery completed!" -ForegroundColor Green
