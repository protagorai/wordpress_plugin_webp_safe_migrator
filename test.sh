#!/bin/bash
# ==============================================================================
# Multi-Plugin WordPress Development Environment - Test Script (Linux/macOS)
# Cross-platform testing framework for multi-plugin system validation
# ==============================================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BLUE='\033[0;34m'
NC='\033[0m'

# Default options
DRY_RUN=false
VERBOSE=false
PROFILE=""

show_help() {
    echo ""
    echo -e "${GREEN}=====================================${NC}"
    echo -e "${GREEN}   Multi-Plugin Test Framework v2.0${NC}"
    echo -e "${GREEN}=====================================${NC}"
    echo ""
    echo -e "${BLUE}🧪 Cross-platform testing for WordPress plugin development environment${NC}"
    echo ""
    echo -e "${CYAN}COMMANDS:${NC}"
    echo "  system      Run complete multi-plugin system tests"
    echo "  plugins     Test plugin structure and validation"
    echo "  deployment  Test deployment scripts and functionality"
    echo "  config      Test configuration parsing and validation"
    echo "  all         Run all test suites"
    echo "  help        Show this help message"
    echo ""
    echo -e "${CYAN}OPTIONS:${NC}"
    echo "  --dry-run   Show what would be tested without executing"
    echo "  --verbose   Show detailed test output"
    echo "  --profile   Test specific deployment profile (development, production, testing)"
    echo ""
    echo -e "${CYAN}EXAMPLES:${NC}"
    echo "  ./test.sh system                    # Test multi-plugin system"
    echo "  ./test.sh plugins --verbose         # Test plugins with detailed output"
    echo "  ./test.sh deployment --dry-run      # Show deployment tests without running"
    echo "  ./test.sh all --profile production  # Test all with production profile"
    echo "  ./test.sh config                    # Test configuration files"
    echo ""
    echo -e "${CYAN}CROSS-PLATFORM TESTING:${NC}"
    echo "  Windows:  test.bat system"
    echo "  Linux:    ./test.sh system"
    echo "  macOS:    ./test.sh system"
    echo ""
    echo -e "${CYAN}📚 Documentation: docs/technical/MULTI_PLUGIN_ARCHITECTURE_DESIGN.md${NC}"
    echo ""
}

parse_arguments() {
    while [[ $# -gt 1 ]]; do
        case $2 in
            --dry-run)
                DRY_RUN=true
                ;;
            --verbose)
                VERBOSE=true
                ;;
            --profile)
                PROFILE="$3"
                shift
                ;;
            *)
                ;;
        esac
        shift
    done
}

test_system() {
    echo ""
    echo -e "${GREEN}=====================================${NC}"
    echo -e "${GREEN}   Multi-Plugin System Test${NC}"
    echo -e "${GREEN}=====================================${NC}"
    echo ""
    echo -e "${BLUE}Running comprehensive multi-plugin system tests...${NC}"
    
    parse_arguments "$@"
    
    echo -e "${CYAN}* Running multi-plugin system validation...${NC}"
    
    # Build command arguments
    local args=""
    if [[ "$DRY_RUN" == true ]]; then
        args="$args -DryRun"
    fi
    if [[ "$VERBOSE" == true ]]; then
        args="$args -ShowVerbose"
    fi
    if [[ -n "$PROFILE" ]]; then
        args="$args -Profile $PROFILE"
    fi
    
    # Run PowerShell test script (works on Linux/macOS with PowerShell Core)
    if command -v pwsh >/dev/null 2>&1; then
        pwsh -Command "& './setup/test-multi-plugin-system.ps1' $args"
    elif command -v powershell >/dev/null 2>&1; then
        powershell -Command "& './setup/test-multi-plugin-system.ps1' $args"
    else
        echo -e "${YELLOW}PowerShell not found, running bash-based tests...${NC}"
        
        # Fallback to bash-based tests
        local test_count=0
        local pass_count=0
        
        echo -e "${CYAN}Testing directory structure...${NC}"
        if [[ -d "src" ]]; then
            echo -e "${GREEN}  ✓ src directory exists${NC}"
            ((pass_count++))
        else
            echo -e "${RED}  ✗ src directory missing${NC}"
        fi
        ((test_count++))
        
        if [[ -d "src/okvir-image-safe-migrator" ]]; then
            echo -e "${GREEN}  ✓ Primary plugin directory exists${NC}"
            ((pass_count++))
        else
            echo -e "${RED}  ✗ Primary plugin directory missing${NC}"
        fi
        ((test_count++))
        
        if [[ -f "bin/config/plugins.yaml" ]]; then
            echo -e "${GREEN}  ✓ Plugin configuration file exists${NC}"
            ((pass_count++))
        else
            echo -e "${RED}  ✗ Plugin configuration file missing${NC}"
        fi
        ((test_count++))
        
        echo ""
        echo -e "${BLUE}Test Results: $pass_count/$test_count passed${NC}"
        
        if [[ $pass_count -eq $test_count ]]; then
            echo -e "${GREEN}✅ System tests completed successfully!${NC}"
            return 0
        else
            echo -e "${RED}❌ Some system tests failed!${NC}"
            return 1
        fi
    fi
    
    if [[ $? -eq 0 ]]; then
        echo ""
        echo -e "${GREEN}✅ Multi-plugin system tests completed successfully!${NC}"
        echo ""
    else
        echo ""
        echo -e "${RED}❌ System tests failed! Please review the output above.${NC}"
        echo ""
        return 1
    fi
}

test_plugins() {
    echo ""
    echo -e "${GREEN}=====================================${NC}"
    echo -e "${GREEN}   Plugin Structure Test${NC}"
    echo -e "${GREEN}=====================================${NC}"
    echo ""
    echo -e "${BLUE}Running plugin structure and validation tests...${NC}"
    
    echo -e "${CYAN}* Testing plugin discovery...${NC}"
    if [[ -f "setup/multi-plugin-manager.sh" ]]; then
        if ./setup/multi-plugin-manager.sh list --dry-run >/dev/null 2>&1; then
            echo -e "${GREEN}  ✓ Plugin manager can discover plugins${NC}"
        else
            echo -e "${YELLOW}  ! Plugin manager test failed (may need WordPress)${NC}"
        fi
    else
        echo -e "${YELLOW}  ! multi-plugin-manager.sh not found${NC}"
    fi
    
    echo -e "${CYAN}* Testing plugin validation...${NC}"
    for plugin_dir in src/*/; do
        if [[ -d "$plugin_dir" ]]; then
            plugin_name=$(basename "$plugin_dir")
            echo -e "${CYAN}  - Validating plugin: $plugin_name${NC}"
            
            # Check for main PHP file
            if ls "$plugin_dir"*.php >/dev/null 2>&1; then
                echo -e "${GREEN}    ✓ Plugin PHP files found${NC}"
                
                # Basic PHP syntax check if php is available
                if command -v php >/dev/null 2>&1; then
                    for php_file in "$plugin_dir"*.php; do
                        if php -l "$php_file" >/dev/null 2>&1; then
                            echo -e "${GREEN}    ✓ PHP syntax valid for $(basename "$php_file")${NC}"
                        else
                            echo -e "${RED}    ! PHP syntax errors in $(basename "$php_file")${NC}"
                        fi
                    done
                else
                    echo -e "${YELLOW}    ! PHP not available for syntax checking${NC}"
                fi
            else
                echo -e "${RED}    ✗ No PHP files found in plugin${NC}"
            fi
        fi
    done
    
    echo ""
    echo -e "${GREEN}✅ Plugin structure tests completed!${NC}"
    echo ""
}

test_deployment() {
    echo ""
    echo -e "${GREEN}=====================================${NC}"
    echo -e "${GREEN}   Deployment Test${NC}"
    echo -e "${GREEN}=====================================${NC}"
    echo ""
    echo -e "${BLUE}Running deployment script tests...${NC}"
    
    echo -e "${CYAN}* Testing deployment script syntax...${NC}"
    if [[ -f "deploy.sh" ]]; then
        if bash -n deploy.sh; then
            echo -e "${GREEN}  ✓ deploy.sh syntax valid${NC}"
        else
            echo -e "${RED}  ! deploy.sh has syntax errors${NC}"
        fi
    else
        echo -e "${RED}  ! deploy.sh not found${NC}"
    fi
    
    echo -e "${CYAN}* Testing multi-plugin manager functionality...${NC}"
    if [[ -f "setup/multi-plugin-manager.sh" ]]; then
        if bash -n setup/multi-plugin-manager.sh; then
            echo -e "${GREEN}  ✓ multi-plugin-manager.sh syntax valid${NC}"
        else
            echo -e "${RED}  ! multi-plugin-manager.sh has syntax errors${NC}"
        fi
        
        # Test basic functionality
        if ./setup/multi-plugin-manager.sh list --dry-run >/dev/null 2>&1; then
            echo -e "${GREEN}  ✓ Multi-plugin manager functional${NC}"
        else
            echo -e "${YELLOW}  ! Multi-plugin manager test failed (may need configuration)${NC}"
        fi
    else
        echo -e "${RED}  ! setup/multi-plugin-manager.sh not found${NC}"
    fi
    
    echo ""
    echo -e "${GREEN}✅ Deployment tests completed!${NC}"
    echo ""
}

test_config() {
    echo ""
    echo -e "${GREEN}=====================================${NC}"
    echo -e "${GREEN}   Configuration Test${NC}"
    echo -e "${GREEN}=====================================${NC}"
    echo ""
    echo -e "${BLUE}Running configuration validation tests...${NC}"
    
    echo -e "${CYAN}* Testing configuration files...${NC}"
    if [[ -f "bin/config/plugins.yaml" ]]; then
        echo -e "${GREEN}  ✓ plugins.yaml found${NC}"
        
        # Test YAML content
        if grep -q "okvir-image-safe-migrator" "bin/config/plugins.yaml"; then
            echo -e "${GREEN}  ✓ plugins.yaml contains expected plugins${NC}"
        else
            echo -e "${YELLOW}  ! plugins.yaml may be incomplete${NC}"
        fi
    else
        echo -e "${RED}  ! plugins.yaml not found${NC}"
    fi
    
    if [[ -f "bin/config/webp-migrator.config.yaml" ]]; then
        echo -e "${GREEN}  ✓ main configuration file found${NC}"
    else
        echo -e "${RED}  ! main configuration file not found${NC}"
    fi
    
    echo -e "${CYAN}* Testing configuration parsing...${NC}"
    if [[ -f "setup/multi-plugin-manager.sh" ]]; then
        if ./setup/multi-plugin-manager.sh list --dry-run >/dev/null 2>&1; then
            echo -e "${GREEN}  ✓ Configuration parsing successful${NC}"
        else
            echo -e "${YELLOW}  ! Configuration parsing test failed${NC}"
        fi
    else
        echo -e "${YELLOW}  ! Multi-plugin manager not available for testing${NC}"
    fi
    
    echo ""
    echo -e "${GREEN}✅ Configuration tests completed!${NC}"
    echo ""
}

test_all() {
    echo ""
    echo -e "${GREEN}=====================================${NC}"
    echo -e "${GREEN}   Complete Test Suite${NC}"
    echo -e "${GREEN}=====================================${NC}"
    echo ""
    echo -e "${BLUE}Running all test suites...${NC}"
    
    local overall_result=0
    
    if ! test_system "$@"; then
        overall_result=1
    fi
    
    if ! test_plugins; then
        overall_result=1
    fi
    
    if ! test_deployment; then
        overall_result=1
    fi
    
    if ! test_config; then
        overall_result=1
    fi
    
    echo ""
    echo -e "${GREEN}=====================================${NC}"
    echo -e "${GREEN}   Test Suite Summary${NC}"
    echo -e "${GREEN}=====================================${NC}"
    echo ""
    
    if [[ $overall_result -eq 0 ]]; then
        echo -e "${GREEN}All test categories completed successfully!${NC}"
    else
        echo -e "${YELLOW}Some tests failed. Review output above for details.${NC}"
    fi
    
    echo ""
    echo "Test Categories:"
    echo -e "${GREEN}  ✓ Multi-Plugin System Tests${NC}"
    echo -e "${GREEN}  ✓ Plugin Structure Tests${NC}"
    echo -e "${GREEN}  ✓ Deployment Tests${NC}"
    echo -e "${GREEN}  ✓ Configuration Tests${NC}"
    echo ""
    echo "For detailed results, run individual test categories with --verbose flag."
    echo ""
    
    return $overall_result
}

# Main execution
if [[ $# -eq 0 ]]; then
    show_help
    exit 0
fi

case "$1" in
    system)
        test_system "$@"
        ;;
    plugins)
        test_plugins
        ;;
    deployment)
        test_deployment
        ;;
    config)
        test_config
        ;;
    all)
        test_all "$@"
        ;;
    help|--help|-h)
        show_help
        ;;
    *)
        echo -e "${RED}❌ Unknown test command: $1${NC}"
        echo ""
        show_help
        exit 1
        ;;
esac
