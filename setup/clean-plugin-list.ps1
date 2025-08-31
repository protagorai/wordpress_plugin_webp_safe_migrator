# Clean Plugin Discovery Script
param([string]$Action = "list")

Write-Host "=== Plugin Discovery ===" -ForegroundColor Green

if (Test-Path "src") {
    $pluginDirs = Get-ChildItem -Path "src" -Directory
    Write-Host "Found $($pluginDirs.Count) directories in src/" -ForegroundColor Cyan
    
    foreach ($dir in $pluginDirs) {
        $phpFiles = Get-ChildItem -Path $dir.FullName -Filter "*.php"
        if ($phpFiles.Count -gt 0) {
            Write-Host "  Plugin: $($dir.Name) - $($phpFiles.Count) PHP files" -ForegroundColor Green
            
            $hasAdmin = Test-Path (Join-Path $dir.FullName "admin")
            $hasIncludes = Test-Path (Join-Path $dir.FullName "includes")
            
            if ($hasAdmin -and $hasIncludes) {
                Write-Host "    Self-contained: Yes (complex plugin)" -ForegroundColor Green
            } elseif ($phpFiles.Count -eq 1) {
                Write-Host "    Self-contained: Yes (simple plugin)" -ForegroundColor Green
            } else {
                Write-Host "    Self-contained: Partial" -ForegroundColor Yellow
            }
        } else {
            Write-Host "  Directory: $($dir.Name) - No PHP files" -ForegroundColor Yellow
        }
    }
    
    Write-Host "Plugin discovery completed successfully" -ForegroundColor Green
} else {
    Write-Host "src/ directory not found" -ForegroundColor Red
    exit 1
}
