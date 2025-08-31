# Test Script for Multi-Plugin System
# This script validates the multi-plugin architecture and configuration

param(
    [switch]$DryRun = $true,
    [switch]$Verbose = $false
)

$ErrorActionPreference = "Continue"

# Colors for output
function Write-Success { param([string]$Message) Write-Host "[SUCCESS] $Message" -ForegroundColor Green }
function Write-Info { param([string]$Message) Write-Host "[INFO] $Message" -ForegroundColor Cyan }
function Write-Warning { param([string]$Message) Write-Host "[WARNING] $Message" -ForegroundColor Yellow }
function Write-Error { param([string]$Message) Write-Host "[ERROR] $Message" -ForegroundColor Red }

Write-Info "=== Multi-Plugin System Test ==="
Write-Info "Dry Run: $DryRun"
Write-Info "Verbose: $Verbose"
Write-Host ""

$TestResults = @{
    Passed = 0
    Failed = 0
    Warnings = 0
}

function Test-FileExists {
    param([string]$FilePath, [string]$Description)
    
    Write-Info "Testing: $Description"
    if (Test-Path $FilePath) {
        Write-Success "  File exists: $FilePath"
        $TestResults.Passed++
        return $true
    } else {
        Write-Error "  File missing: $FilePath"
        $TestResults.Failed++
        return $false
    }
}

function Test-DirectoryStructure {
    param([string]$DirectoryPath, [string]$Description)
    
    Write-Info "Testing: $Description"
    if (Test-Path $DirectoryPath -PathType Container) {
        $fileCount = (Get-ChildItem -Path $DirectoryPath -Force | Measure-Object).Count
        Write-Success "  Directory exists: $DirectoryPath ($fileCount items)"
        $TestResults.Passed++
        return $true
    } else {
        Write-Error "  Directory missing: $DirectoryPath"
        $TestResults.Failed++
        return $false
    }
}

function Test-PluginStructure {
    param([string]$PluginPath, [string]$PluginName)
    
    Write-Info "Testing plugin structure: $PluginName"
    
    $mainFile = Get-ChildItem -Path $PluginPath -Filter "*.php" | Where-Object { $_.Name -match "^$PluginName" } | Select-Object -First 1
    
    if ($mainFile) {
        Write-Success "  Main plugin file found: $($mainFile.Name)"
        
        # Check for plugin header
        $content = Get-Content -Path $mainFile.FullName -Head 20 -ErrorAction SilentlyContinue
        $hasHeader = $content | Where-Object { $_ -match "Plugin Name:" }
        
        if ($hasHeader) {
            Write-Success "  Plugin header found"
            $TestResults.Passed++
        } else {
            Write-Warning "  Plugin header not found or incomplete"
            $TestResults.Warnings++
        }
        
        return $true
    } else {
        Write-Error "  Main plugin file not found for $PluginName"
        $TestResults.Failed++
        return $false
    }
}

function Test-ConfigurationFiles {
    Write-Info "Testing configuration files..."
    
    # Test main configuration files
    Test-FileExists "bin/config/plugins.yaml" "Multi-plugin configuration file"
    Test-FileExists "bin/config/webp-migrator.config.yaml" "Main configuration file"
    
    # Test if configuration files contain expected content
    if (Test-Path "bin/config/plugins.yaml") {
        $content = Get-Content "bin/config/plugins.yaml" -Raw
        if ($content -match "okvir-image-safe-migrator" -and $content -match "example-second-plugin") {
            Write-Success "  Plugin configuration contains expected plugins"
            $TestResults.Passed++
        } else {
            Write-Warning "  Plugin configuration may be incomplete"
            $TestResults.Warnings++
        }
    }
}

function Test-PluginManagers {
    Write-Info "Testing plugin manager scripts..."
    
    Test-FileExists "setup/multi-plugin-manager.ps1" "PowerShell multi-plugin manager"
    Test-FileExists "setup/multi-plugin-manager.sh" "Bash multi-plugin manager"
    Test-FileExists "setup/plugin-manager.ps1" "Legacy PowerShell plugin manager (backward compatibility)"
    Test-FileExists "setup/plugin-manager.sh" "Legacy Bash plugin manager (backward compatibility)"
}

function Test-PluginManagerFunctionality {
    if (-not $DryRun) {
        Write-Info "Testing plugin manager functionality..."
        
        try {
            # Test list command
            $output = & ".\setup\multi-plugin-manager.ps1" -Action "list" -DryRun -Verbose 2>&1
            if ($output -match "okvir-image-safe-migrator") {
                Write-Success "  Plugin manager can list available plugins"
                $TestResults.Passed++
            } else {
                Write-Warning "  Plugin manager list output may be incomplete"
                $TestResults.Warnings++
            }
        } catch {
            Write-Error "  Plugin manager test failed: $_"
            $TestResults.Failed++
        }
    } else {
        Write-Info "Skipping plugin manager functionality test (dry run mode)"
    }
}

function Test-DocumentationConsistency {
    Write-Info "Testing documentation consistency..."
    
    # Check if design document exists and mentions key concepts
    if (Test-Path "docs/technical/MULTI_PLUGIN_ARCHITECTURE_DESIGN.md") {
        $content = Get-Content "docs/technical/MULTI_PLUGIN_ARCHITECTURE_DESIGN.md" -Raw
        
        $keyTerms = @("multi-plugin", "okvir-image-safe-migrator", "deployment profile", "configuration")
        $missingTerms = @()
        
        foreach ($term in $keyTerms) {
            if ($content -notmatch $term) {
                $missingTerms += $term
            }
        }
        
        if ($missingTerms.Count -eq 0) {
            Write-Success "  Design document contains all key terms"
            $TestResults.Passed++
        } else {
            Write-Warning "  Design document missing terms: $($missingTerms -join ', ')"
            $TestResults.Warnings++
        }
    } else {
        Write-Error "  Design documentation not found"
        $TestResults.Failed++
    }
}

# =============================================================================
# RUN TESTS
# =============================================================================

Write-Info "Running Multi-Plugin System Tests..."
Write-Host ""

# Test 1: Directory Structure
Write-Info "=== Test 1: Directory Structure ==="
Test-DirectoryStructure "src" "Source plugins directory"
Test-DirectoryStructure "src/okvir-image-safe-migrator" "Renamed primary plugin directory"
Test-DirectoryStructure "src/example-second-plugin" "Example second plugin directory"
Test-DirectoryStructure "bin/config" "Configuration directory"
Test-DirectoryStructure "setup" "Setup scripts directory"
Write-Host ""

# Test 2: Plugin Structure
Write-Info "=== Test 2: Plugin Structure ==="
if (Test-Path "src/okvir-image-safe-migrator") {
    Test-PluginStructure "src/okvir-image-safe-migrator" "okvir-image-safe-migrator"
    Test-FileExists "src/okvir-image-safe-migrator/admin/css/admin.css" "Primary plugin admin CSS"
    Test-FileExists "src/okvir-image-safe-migrator/admin/js/admin.js" "Primary plugin admin JS"
    Test-DirectoryStructure "src/okvir-image-safe-migrator/includes" "Primary plugin includes directory"
}

if (Test-Path "src/example-second-plugin") {
    Test-PluginStructure "src/example-second-plugin" "example-second-plugin"
}
Write-Host ""

# Test 3: Configuration Files
Write-Info "=== Test 3: Configuration Files ==="
Test-ConfigurationFiles
Write-Host ""

# Test 4: Plugin Manager Scripts
Write-Info "=== Test 4: Plugin Manager Scripts ==="
Test-PluginManagers
Write-Host ""

# Test 5: Plugin Manager Functionality
Write-Info "=== Test 5: Plugin Manager Functionality ==="
Test-PluginManagerFunctionality
Write-Host ""

# Test 6: Documentation Consistency
Write-Info "=== Test 6: Documentation Consistency ==="
Test-DocumentationConsistency
Write-Host ""

# Test 7: Backward Compatibility
Write-Info "=== Test 7: Backward Compatibility ==="
Write-Info "Testing: Legacy plugin file removal"
if (Test-Path "src/webp-safe-migrator.php") {
    Write-Warning "  Legacy plugin file still exists - migration incomplete"
    $TestResults.Warnings++
} else {
    Write-Success "  Legacy plugin file properly removed"
    $TestResults.Passed++
}
Write-Host ""

# =============================================================================
# TEST RESULTS
# =============================================================================

Write-Info "=== Test Results Summary ==="
Write-Host ""

$totalTests = $TestResults.Passed + $TestResults.Failed + $TestResults.Warnings

Write-Info "Total Tests: $totalTests"
Write-Success "Passed: $($TestResults.Passed)"
if ($TestResults.Warnings -gt 0) {
    Write-Warning "Warnings: $($TestResults.Warnings)"
}
if ($TestResults.Failed -gt 0) {
    Write-Error "Failed: $($TestResults.Failed)"
}

$successRate = if ($totalTests -gt 0) { [math]::Round(($TestResults.Passed / $totalTests) * 100, 1) } else { 0 }
Write-Info "Success Rate: $successRate%"

Write-Host ""

if ($TestResults.Failed -eq 0) {
    if ($TestResults.Warnings -eq 0) {
        Write-Success "🎉 ALL TESTS PASSED! Multi-plugin system is ready for use."
    } else {
        Write-Success "✅ All critical tests passed. Some warnings to review."
    }
    
    Write-Host ""
    Write-Info "Next Steps:"
    Write-Info "1. Review any warnings above"
    Write-Info "2. Test the system with: .\setup\multi-plugin-manager.ps1 list"
    Write-Info "3. Deploy plugins with: .\setup\multi-plugin-manager.ps1 install-all --profile development"
    Write-Info "4. Update documentation as needed"
    
} else {
    Write-Error "❌ Some tests failed. Please review and fix issues before proceeding."
    Write-Host ""
    Write-Info "Common fixes:"
    Write-Info "1. Ensure all plugin files are properly created"
    Write-Info "2. Verify configuration files contain expected content"
    Write-Info "3. Check that plugin directories have correct structure"
    Write-Info "4. Update plugin manager scripts if needed"
    
    exit 1
}

Write-Host ""
Write-Info "Multi-plugin system test completed."
