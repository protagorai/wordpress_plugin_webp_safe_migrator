#!/bin/bash
# WebP Safe Migrator - Configuration Generator Script
# Shell wrapper for the Python configuration generator

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BLUE='\033[0;34m'
NC='\033[0m'

# Logging functions
log_info() { echo -e "${CYAN}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }
log_header() { echo -e "${BLUE}=== $1 ===${NC}"; }

# Default values
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE=""
OUTPUT_DIR="$SCRIPT_DIR/generated"
VERBOSE=false
AUTO_INSTALL=false
CLEANUP_EXISTING=false

# Show usage
show_usage() {
    echo "WebP Safe Migrator - Configuration Generator"
    echo ""
    echo "Usage: $0 CONFIG_FILE [OPTIONS]"
    echo ""
    echo "Arguments:"
    echo "  CONFIG_FILE              Path to YAML configuration file"
    echo ""
    echo "Options:"
    echo "  -o, --output DIR         Output directory (default: ./generated)"
    echo "  -v, --verbose            Verbose output"
    echo "  -a, --auto-install       Automatically start installation after generation"
    echo "  -c, --cleanup            Clean up existing generated files first"
    echo "  -h, --help              Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 simple-config.yaml"
    echo "  $0 my-config.yaml -o ./my-setup -a"
    echo "  $0 webp-migrator-config.yaml --verbose --auto-install"
    echo ""
    echo "Quick start:"
    echo "  cp simple-config.yaml my-config.yaml"
    echo "  # Edit my-config.yaml as needed"
    echo "  $0 my-config.yaml --auto-install"
}

# Check dependencies
check_dependencies() {
    log_info "Checking dependencies..."
    
    # Check Python
    if ! command -v python3 >/dev/null 2>&1; then
        log_error "Python 3 is required but not found"
        log_info "Please install Python 3:"
        echo "  Ubuntu/Debian: sudo apt-get install python3 python3-pip"
        echo "  CentOS/RHEL: sudo yum install python3 python3-pip"
        echo "  macOS: brew install python3"
        echo "  Windows: Download from https://python.org"
        exit 1
    fi
    
    # Check PyYAML
    if ! python3 -c "import yaml" >/dev/null 2>&1; then
        log_warning "PyYAML not found, installing..."
        pip3 install PyYAML
    fi
    
    # Check config generator script
    if [[ ! -f "$SCRIPT_DIR/config-generator.py" ]]; then
        log_error "Configuration generator not found: $SCRIPT_DIR/config-generator.py"
        exit 1
    fi
    
    log_success "All dependencies are available"
}

# Validate configuration file
validate_config() {
    local config_file="$1"
    
    if [[ ! -f "$config_file" ]]; then
        log_error "Configuration file not found: $config_file"
        echo ""
        log_info "Available example configurations:"
        if [[ -f "$SCRIPT_DIR/simple-config.yaml" ]]; then
            echo "  📄 simple-config.yaml - Minimal configuration for quick setup"
        fi
        if [[ -f "$SCRIPT_DIR/webp-migrator-config.yaml" ]]; then
            echo "  📄 webp-migrator-config.yaml - Complete configuration with all options"
        fi
        echo ""
        echo "Copy an example configuration and customize it:"
        echo "  cp $SCRIPT_DIR/simple-config.yaml my-config.yaml"
        exit 1
    fi
    
    # Validate YAML syntax
    log_info "Validating configuration file..."
    if ! python3 -c "import yaml; yaml.safe_load(open('$config_file'))" >/dev/null 2>&1; then
        log_error "Invalid YAML syntax in configuration file"
        exit 1
    fi
    
    log_success "Configuration file is valid"
}

# Clean up existing files
cleanup_existing() {
    if [[ -d "$OUTPUT_DIR" ]] && [[ "$CLEANUP_EXISTING" == true ]]; then
        log_info "Cleaning up existing generated files..."
        rm -rf "$OUTPUT_DIR"
        log_success "Cleanup completed"
    fi
}

# Generate configurations
generate_configs() {
    local config_file="$1"
    local output_dir="$2"
    
    log_header "Generating Configuration Files"
    
    # Create output directory
    mkdir -p "$output_dir"
    
    # Run Python generator
    local python_args=(
        "$SCRIPT_DIR/config-generator.py"
        "$config_file"
        "--output" "$output_dir"
    )
    
    if [[ "$VERBOSE" == true ]]; then
        python_args+=("--verbose")
    fi
    
    if ! python3 "${python_args[@]}"; then
        log_error "Configuration generation failed"
        exit 1
    fi
    
    log_success "Configuration files generated successfully"
}

# Show generated files summary
show_summary() {
    local output_dir="$1"
    
    log_header "Generated Files Summary"
    
    if [[ -f "$output_dir/docker-compose.yml" ]]; then
        echo "✅ docker-compose.yml - Container orchestration"
    fi
    
    if [[ -f "$output_dir/.env" ]]; then
        echo "✅ .env - Environment variables"
    fi
    
    if [[ -f "$output_dir/mysql-init/01-webp-migrator-init.sql" ]]; then
        echo "✅ mysql-init/01-webp-migrator-init.sql - Database initialization"
    fi
    
    if [[ -f "$output_dir/install-automated.sh" ]]; then
        echo "✅ install-automated.sh - Automated WordPress installation"
    fi
    
    echo ""
    log_info "📁 All files generated in: $output_dir"
}

# Auto installation
auto_install() {
    local output_dir="$1"
    
    log_header "Starting Automated Installation"
    
    # Change to output directory
    cd "$output_dir"
    
    # Check if Docker/Podman is available
    local container_engine=""
    if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
        container_engine="docker"
    elif command -v podman >/dev/null 2>&1; then
        container_engine="podman"
    else
        log_error "No container engine found (Docker or Podman required)"
        log_info "Please install Docker or Podman and try again"
        exit 1
    fi
    
    log_info "Using container engine: $container_engine"
    
    # Start containers
    if [[ "$container_engine" == "docker" ]]; then
        log_info "Starting containers with Docker Compose..."
        docker-compose up -d
    else
        log_info "Starting containers with Podman Compose..."
        podman-compose up -d
    fi
    
    # Wait a moment for services to initialize
    log_info "Waiting for services to initialize..."
    sleep 10
    
    # Run automated installation
    if [[ -f "./install-automated.sh" ]]; then
        log_info "Running automated WordPress installation..."
        chmod +x ./install-automated.sh
        ./install-automated.sh
    else
        log_warning "Automated installation script not found"
    fi
    
    log_success "Installation completed!"
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -h|--help)
            show_usage
            exit 0
            ;;
        -o|--output)
            OUTPUT_DIR="$2"
            shift 2
            ;;
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        -a|--auto-install)
            AUTO_INSTALL=true
            shift
            ;;
        -c|--cleanup)
            CLEANUP_EXISTING=true
            shift
            ;;
        -*)
            log_error "Unknown option: $1"
            show_usage
            exit 1
            ;;
        *)
            if [[ -z "$CONFIG_FILE" ]]; then
                CONFIG_FILE="$1"
            else
                log_error "Multiple configuration files specified"
                show_usage
                exit 1
            fi
            shift
            ;;
    esac
done

# Validate required arguments
if [[ -z "$CONFIG_FILE" ]]; then
    log_error "Configuration file is required"
    show_usage
    exit 1
fi

# Main execution
main() {
    log_header "WebP Safe Migrator - Configuration Generator"
    
    check_dependencies
    validate_config "$CONFIG_FILE"
    cleanup_existing
    generate_configs "$CONFIG_FILE" "$OUTPUT_DIR"
    show_summary "$OUTPUT_DIR"
    
    if [[ "$AUTO_INSTALL" == true ]]; then
        echo ""
        log_info "Auto-installation requested..."
        auto_install "$OUTPUT_DIR"
    else
        echo ""
        log_header "Next Steps"
        echo "1. Review generated configuration files in: $OUTPUT_DIR"
        echo "2. Start your environment:"
        echo "   cd $OUTPUT_DIR"
        echo "   docker-compose up -d"
        echo "   ./install-automated.sh"
        echo ""
        echo "Or run with --auto-install to do this automatically:"
        echo "   $0 $CONFIG_FILE --auto-install"
    fi
}

# Run main function
main
