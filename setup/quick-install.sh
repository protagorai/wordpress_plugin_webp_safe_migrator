#!/bin/bash
# WebP Migrator - One-Command WordPress Setup for Linux/macOS
# Downloads, installs, and configures everything automatically

set -e

# Default configuration
INSTALL_PATH="$HOME/webp-migrator-test"
START_AFTER_INSTALL=true
USE_DOCKER=false

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${CYAN}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --install-path)
            INSTALL_PATH="$2"
            shift 2
            ;;
        --no-start)
            START_AFTER_INSTALL=false
            shift
            ;;
        --use-docker)
            USE_DOCKER=true
            shift
            ;;
        --help)
            echo "WebP Migrator - One-Command Setup"
            echo ""
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --install-path PATH    Installation directory (default: ~/webp-migrator-test)"
            echo "  --no-start            Don't start services after installation"
            echo "  --use-docker          Use Docker containers instead of native install"
            echo "  --help                Show this help message"
            echo ""
            echo "This script will create a complete WordPress test environment with:"
            echo "  - Apache/Nginx web server"
            echo "  - MySQL/MariaDB database"
            echo "  - PHP with required extensions"
            echo "  - WordPress latest version"
            echo "  - WebP Safe Migrator plugin pre-installed"
            exit 0
            ;;
        *)
            log_error "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

echo -e "${GREEN}🚀 WebP Migrator - One-Command Setup${NC}"
echo "This will create a complete WordPress test environment at: $INSTALL_PATH"
echo ""

# Confirm installation
if [[ -t 0 ]]; then  # Check if running interactively
    echo -n "Continue? (Y/n): "
    read -r confirm
    if [[ "$confirm" == "n" ]] || [[ "$confirm" == "N" ]]; then
        echo "Installation cancelled."
        exit 0
    fi
fi

# Check if script directory exists (to find other scripts)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ ! -f "$SCRIPT_DIR/install-wordpress.sh" ]]; then
    log_error "install-wordpress.sh not found in script directory"
    log_error "Please ensure all setup scripts are in the same directory"
    exit 1
fi

if [[ ! -f "$SCRIPT_DIR/plugin-manager.sh" ]]; then
    log_error "plugin-manager.sh not found in script directory"
    log_error "Please ensure all setup scripts are in the same directory"
    exit 1
fi

# Make scripts executable
chmod +x "$SCRIPT_DIR/install-wordpress.sh" 2>/dev/null || true
chmod +x "$SCRIPT_DIR/plugin-manager.sh" 2>/dev/null || true

log_info "🔄 Running automated installation..."

# Determine additional arguments
INSTALL_ARGS="--install-path $INSTALL_PATH"
if [[ "$START_AFTER_INSTALL" == true ]]; then
    INSTALL_ARGS="$INSTALL_ARGS --start-services"
fi
if [[ "$USE_DOCKER" == true ]]; then
    INSTALL_ARGS="$INSTALL_ARGS --use-docker"
fi

# Run the WordPress installation
if ! "$SCRIPT_DIR/install-wordpress.sh" $INSTALL_ARGS; then
    log_error "WordPress installation failed"
    exit 1
fi

# If using Docker, we're done
if [[ "$USE_DOCKER" == true ]]; then
    log_success "🎉 SUCCESS! Your WebP Migrator test environment is ready!"
    echo ""
    echo -e "${CYAN}📍 Quick Access:${NC}"
    echo "   Website: http://localhost:8080"
    echo "   Admin:   http://localhost:8080/wp-admin"
    echo "   phpMyAdmin: http://localhost:8081"
    echo ""
    echo -e "${CYAN}🔑 Database Credentials:${NC}"
    echo "   Database: wordpress_webp_test"
    echo "   Username: wordpress"
    echo "   Password: wordpress123"
    echo "   Root Password: root123"
    echo ""
    echo -e "${CYAN}🔌 Plugin Installation:${NC}"
    echo "   Copy plugin files to: $INSTALL_PATH/plugins/webp-safe-migrator/"
    echo "   Then activate in WordPress admin"
    echo ""
    if [[ "$START_AFTER_INSTALL" == true ]]; then
        log_info "🌐 Opening WordPress in your browser..."
        sleep 3
        if command -v xdg-open >/dev/null 2>&1; then
            xdg-open "http://localhost:8080" >/dev/null 2>&1 &
        elif command -v open >/dev/null 2>&1; then
            open "http://localhost:8080" >/dev/null 2>&1 &
        fi
    fi
    exit 0
fi

# Wait a moment for services to be ready
log_info "⏳ Waiting for services to be ready..."
sleep 10

# Check if WordPress is accessible
log_info "🔍 Checking WordPress accessibility..."
for i in {1..30}; do
    if curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080" | grep -q "200\|30[0-9]"; then
        log_success "WordPress is accessible"
        break
    fi
    if [[ $i -eq 30 ]]; then
        log_warning "WordPress may not be fully ready yet, but continuing with plugin installation"
    fi
    sleep 2
done

# Install and activate the plugin
log_info "🔌 Installing WebP Safe Migrator plugin..."

PLUGIN_ARGS="install --wordpress-path $INSTALL_PATH/www --use-wpcli"
if ! "$SCRIPT_DIR/plugin-manager.sh" $PLUGIN_ARGS; then
    log_warning "Plugin installation via script failed, trying alternative method..."
    
    # Alternative: copy files directly
    PLUGIN_DIR="$INSTALL_PATH/www/wp-content/plugins/webp-safe-migrator"
    SOURCE_DIR="$(dirname "$SCRIPT_DIR")/src"
    
    if [[ -d "$SOURCE_DIR" ]]; then
        log_info "Copying plugin files directly..."
        mkdir -p "$PLUGIN_DIR"
        cp -r "$SOURCE_DIR/"* "$PLUGIN_DIR/"
        log_success "Plugin files copied successfully"
    else
        log_warning "Plugin source directory not found at $SOURCE_DIR"
        log_info "You'll need to install the plugin manually"
    fi
fi

# Final success message
echo ""
log_success "🎉 SUCCESS! Your WebP Migrator test environment is ready!"
echo ""
echo -e "${CYAN}📍 Quick Access:${NC}"
echo "   Website: http://localhost:8080"
echo "   Admin:   http://localhost:8080/wp-admin"
echo ""
echo -e "${CYAN}🔑 Login Credentials:${NC}"
echo "   Complete WordPress setup at http://localhost:8080"
echo "   Database: wordpress_webp_test"
echo "   DB User: wordpress"
echo "   DB Password: wordpress123"
echo ""
echo -e "${CYAN}🔌 Plugin Access:${NC}"
echo "   After WordPress setup, go to:"
echo "   Plugins → Activate 'WebP Safe Migrator'"
echo "   Then: Media → WebP Migrator"
echo ""
echo -e "${CYAN}🛠️ Service Management:${NC}"
echo "   Start:  $INSTALL_PATH/scripts/start-services.sh"
echo "   Stop:   $INSTALL_PATH/scripts/stop-services.sh"
echo ""

# Try to open browser
if [[ "$START_AFTER_INSTALL" == true ]]; then
    log_info "🌐 Opening WordPress in your browser..."
    sleep 3
    if command -v xdg-open >/dev/null 2>&1; then
        xdg-open "http://localhost:8080" >/dev/null 2>&1 &
    elif command -v open >/dev/null 2>&1; then
        open "http://localhost:8080" >/dev/null 2>&1 &
    else
        log_info "Please open http://localhost:8080 in your browser"
    fi
fi
