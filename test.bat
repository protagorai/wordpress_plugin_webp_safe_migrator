@echo off
setlocal enabledelayedexpansion
REM ==============================================================================
REM Multi-Plugin WordPress Development Environment - Test Script (Windows)
REM Cross-platform testing framework for multi-plugin system validation
REM ==============================================================================

if "%1"=="" goto show_help

REM Main commands
if /i "%1"=="system" goto test_system
if /i "%1"=="plugins" goto test_plugins
if /i "%1"=="deployment" goto test_deployment
if /i "%1"=="config" goto test_config
if /i "%1"=="all" goto test_all
if /i "%1"=="help" goto show_help

echo ❌ Unknown test command: %1
goto show_help

:show_help
echo.
echo =====================================
echo    Multi-Plugin Test Framework v2.0
echo =====================================
echo.
echo 🧪 Cross-platform testing for WordPress plugin development environment
echo.
echo COMMANDS:
echo   system      Run complete multi-plugin system tests
echo   plugins     Test plugin structure and validation
echo   deployment  Test deployment scripts and functionality
echo   config      Test configuration parsing and validation
echo   all         Run all test suites
echo   help        Show this help message
echo.
echo OPTIONS:
echo   --dry-run   Show what would be tested without executing
echo   --verbose   Show detailed test output
echo   --profile   Test specific deployment profile (development, production, testing)
echo.
echo EXAMPLES:
echo   test.bat system                    # Test multi-plugin system
echo   test.bat plugins --verbose         # Test plugins with detailed output
echo   test.bat deployment --dry-run      # Show deployment tests without running
echo   test.bat all --profile production  # Test all with production profile
echo   test.bat config                    # Test configuration files
echo.
echo CROSS-PLATFORM TESTING:
echo   Windows:  test.bat system
echo   Linux:    ./test.sh system
echo   macOS:    ./test.sh system
echo.
echo 📚 Documentation: docs/technical/MULTI_PLUGIN_ARCHITECTURE_DESIGN.md
echo.
goto end

:test_system
echo.
echo =====================================
echo    Multi-Plugin System Test
echo =====================================
echo.
echo Running comprehensive multi-plugin system tests...

REM Parse additional arguments
set DRY_RUN_FLAG=
set VERBOSE_FLAG=
set PROFILE_ARG=

:parse_system_args
if "%2"=="" goto run_system_test
if /i "%2"=="--dry-run" (
    set DRY_RUN_FLAG=-DryRun
    shift
    goto parse_system_args
)
if /i "%2"=="--verbose" (
    set VERBOSE_FLAG=-ShowVerbose
    shift
    goto parse_system_args
)
if /i "%2"=="--profile" (
    set PROFILE_ARG=-Profile %3
    shift
    shift
    goto parse_system_args
)
shift
goto parse_system_args

:run_system_test
echo * Running multi-plugin system validation...
powershell -ExecutionPolicy Bypass -File "setup\test-multi-plugin-system.ps1" %DRY_RUN_FLAG% %VERBOSE_FLAG% %PROFILE_ARG%

if errorlevel 1 (
    echo.
    echo ❌ System tests failed! Please review the output above.
    echo.
    goto end
)

echo.
echo ✅ Multi-plugin system tests completed successfully!
echo.
goto end

:test_plugins
echo.
echo =====================================
echo    Plugin Structure Test
echo =====================================
echo.
echo Running plugin structure and validation tests...

REM Test plugin discovery and structure
echo * Testing plugin discovery...
powershell -ExecutionPolicy Bypass -File "setup\clean-plugin-list.ps1" -Action "list" 2>nul

if errorlevel 1 (
    echo ! Plugin discovery test failed
) else (
    echo   ✓ Plugin discovery successful
)

echo * Testing plugin validation...
for /d %%i in (src\*) do (
    if exist "%%i\*.php" (
        echo   - Validating plugin: %%~ni
        REM Basic PHP syntax check
        php -l "%%i\*.php" >nul 2>&1
        if errorlevel 1 (
            echo     ! PHP syntax errors found in %%~ni
        ) else (
            echo     ✓ PHP syntax valid for %%~ni
        )
    )
)

echo.
echo ✅ Plugin structure tests completed!
echo.
goto end

:test_deployment
echo.
echo =====================================
echo    Deployment Test
echo =====================================
echo.
echo Running deployment script tests...

echo * Testing deployment script syntax...
echo   - Testing deploy.bat syntax...
cmd /c "deploy.bat help" >nul 2>&1
if errorlevel 1 (
    echo     ! deploy.bat has syntax errors
) else (
    echo     ✓ deploy.bat syntax valid
)

echo * Testing multi-plugin manager functionality...
powershell -ExecutionPolicy Bypass -File "setup\clean-plugin-list.ps1" -Action "list" >nul 2>&1

if errorlevel 1 (
    echo     ! Multi-plugin manager test failed
) else (
    echo     ✓ Multi-plugin manager functional
)

echo.
echo ✅ Deployment tests completed!
echo.
goto end

:test_config
echo.
echo =====================================
echo    Configuration Test
echo =====================================
echo.
echo Running configuration validation tests...

echo * Testing configuration files...
if exist "bin\config\plugins.yaml" (
    echo   ✓ plugins.yaml found
    REM Test YAML syntax (basic)
    findstr /C:"okvir-image-safe-migrator" "bin\config\plugins.yaml" >nul
    if not errorlevel 1 (
        echo   ✓ plugins.yaml contains expected plugins
    ) else (
        echo   ! plugins.yaml may be incomplete
    )
) else (
    echo   ! plugins.yaml not found
)

if exist "bin\config\webp-migrator.config.yaml" (
    echo   ✓ main configuration file found
) else (
    echo   ! main configuration file not found
)

echo * Testing configuration parsing...
powershell -ExecutionPolicy Bypass -File "setup\clean-plugin-list.ps1" -Action "list" >nul 2>&1

if errorlevel 1 (
    echo   ! Configuration parsing failed
) else (
    echo   ✓ Configuration parsing successful
)

echo.
echo ✅ Configuration tests completed!
echo.
goto end

:test_all
echo.
echo =====================================
echo    Complete Test Suite
echo =====================================
echo.
echo Running all test suites...

call :test_system
call :test_plugins  
call :test_deployment
call :test_config

echo.
echo =====================================
echo    Test Suite Summary
echo =====================================
echo.
echo All test categories completed. Review output above for any failures.
echo.
echo Test Categories:
echo   ✓ Multi-Plugin System Tests
echo   ✓ Plugin Structure Tests
echo   ✓ Deployment Tests  
echo   ✓ Configuration Tests
echo.
echo For detailed results, run individual test categories with --verbose flag.
echo.
goto end

:end
REM Clean up environment variables
set DRY_RUN_FLAG=
set VERBOSE_FLAG=
set PROFILE_ARG=
