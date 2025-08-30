#!/bin/bash
# WordPress Development Environment Setup Script - FULLY AUTOMATED
# This script installs LAMP/LEMP stack, WordPress AND completes WordPress installation automatically

set -e

# Default configuration
INSTALL_PATH="$HOME/webp-migrator-test"
WP_VERSION="latest"
PHP_VERSION="8.1"
SITE_TITLE="WebP Migrator Test Site"
ADMIN_USER="admin"
ADMIN_PASSWORD="admin123"
ADMIN_EMAIL="admin@webp-test.local"
SKIP_DOWNLOADS=false
START_SERVICES=true
AUTO_INSTALL=true

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
        --wp-version)
            WP_VERSION="$2"
            shift 2
            ;;
        --php-version)
            PHP_VERSION="$2"
            shift 2
            ;;
        --site-title)
            SITE_TITLE="$2"
            shift 2
            ;;
        --admin-user)
            ADMIN_USER="$2"
            shift 2
            ;;
        --admin-password)
            ADMIN_PASSWORD="$2"
            shift 2
            ;;
        --admin-email)
            ADMIN_EMAIL="$2"
            shift 2
            ;;
        --skip-downloads)
            SKIP_DOWNLOADS=true
            shift
            ;;
        --no-start-services)
            START_SERVICES=false
            shift
            ;;
        --no-auto-install)
            AUTO_INSTALL=false
            shift
            ;;
        --help)
            echo "WordPress Development Environment Setup - FULLY AUTOMATED"
            echo ""
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --install-path PATH      Installation directory (default: ~/webp-migrator-test)"
            echo "  --wp-version VERSION     WordPress version (default: latest)"
            echo "  --php-version VERSION    PHP version (default: 8.1)"
            echo "  --site-title TITLE       WordPress site title (default: 'WebP Migrator Test Site')"
            echo "  --admin-user USER        Admin username (default: admin)"
            echo "  --admin-password PASS    Admin password (default: admin123)"
            echo "  --admin-email EMAIL      Admin email (default: admin@webp-test.local)"
            echo "  --skip-downloads         Skip downloading if files exist"
            echo "  --no-start-services      Don't start services after installation"
            echo "  --no-auto-install        Skip automatic WordPress installation"
            echo "  --help                   Show this help message"
            exit 0
            ;;
        *)
            log_error "Unknown option: $1"
            exit 1
            ;;
    esac
done

echo -e "${GREEN}=== WebP Migrator WordPress Test Environment Setup (AUTOMATED) ===${NC}"
echo "Installation path: $INSTALL_PATH"
echo ""

# Include all the setup code from the base install script
# First run the base installation
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_SCRIPT="$SCRIPT_DIR/install-wordpress.sh"

if [[ ! -f "$BASE_SCRIPT" ]]; then
    log_error "Base installation script not found: $BASE_SCRIPT"
    exit 1
fi

# Make base script executable
chmod +x "$BASE_SCRIPT"

# Prepare arguments for base script
BASE_ARGS="--install-path $INSTALL_PATH --wp-version $WP_VERSION --php-version $PHP_VERSION"
if [[ "$SKIP_DOWNLOADS" == true ]]; then
    BASE_ARGS="$BASE_ARGS --skip-downloads"
fi
if [[ "$START_SERVICES" == true ]]; then
    BASE_ARGS="$BASE_ARGS --start-services"
fi

log_info "Running base WordPress installation..."
if ! "$BASE_SCRIPT" $BASE_ARGS; then
    log_error "Base WordPress installation failed"
    exit 1
fi

# NEW: Automated WordPress Installation
if [[ "$AUTO_INSTALL" == true ]]; then
    log_info "Performing automated WordPress installation..."
    
    # Wait for services to be ready
    sleep 10
    
    # Download and setup WP-CLI
    WPCLI_PATH="$INSTALL_PATH/wp-cli.phar"
    if [[ ! -f "$WPCLI_PATH" ]]; then
        log_info "Downloading WP-CLI..."
        if ! curl -o "$WPCLI_PATH" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar; then
            log_error "Failed to download WP-CLI"
            exit 1
        fi
        chmod +x "$WPCLI_PATH"
    fi
    
    # Create WP-CLI wrapper
    cat > "$INSTALL_PATH/wp" << EOF
#!/bin/bash
cd "$INSTALL_PATH/www"
php "$WPCLI_PATH" "\$@"
EOF
    chmod +x "$INSTALL_PATH/wp"
    
    # Change to WordPress directory
    cd "$INSTALL_PATH/www"
    
    # Wait for database to be ready
    log_info "Waiting for database to be ready..."
    for i in {1..30}; do
        if mysql -h127.0.0.1 -uwordpress -pwordpress123 -e "SELECT 1;" wordpress_webp_test >/dev/null 2>&1; then
            break
        fi
        if [[ $i -eq 30 ]]; then
            log_error "Database not ready after 60 seconds"
            exit 1
        fi
        sleep 2
    done
    
    # Install WordPress core
    log_info "Installing WordPress core..."
    if php "$WPCLI_PATH" core install \
        --url="http://localhost:8080" \
        --title="$SITE_TITLE" \
        --admin_user="$ADMIN_USER" \
        --admin_password="$ADMIN_PASSWORD" \
        --admin_email="$ADMIN_EMAIL" \
        --skip-email; then
        
        log_success "WordPress installed successfully!"
        
        # Install and activate WebP Safe Migrator plugin
        log_info "Installing WebP Safe Migrator plugin..."
        
        # Create plugin directory
        PLUGIN_DIR="$INSTALL_PATH/www/wp-content/plugins/webp-safe-migrator"
        mkdir -p "$PLUGIN_DIR"
        
        # Copy plugin files
        SOURCE_DIR="$(dirname "$SCRIPT_DIR")/src"
        if [[ -d "$SOURCE_DIR" ]]; then
            cp -r "$SOURCE_DIR/"* "$PLUGIN_DIR/"
            
            # Activate plugin via WP-CLI
            if php "$WPCLI_PATH" plugin activate webp-safe-migrator; then
                log_success "WebP Safe Migrator plugin activated!"
            else
                log_warning "Plugin activation failed, you can activate manually in WordPress admin"
            fi
        else
            log_warning "Plugin source directory not found at $SOURCE_DIR"
        fi
        
        # Set up test content
        log_info "Creating test content..."
        
        # Create a test post with instructions
        TEST_POST_CONTENT="<h2>Welcome to WebP Safe Migrator Test Site</h2>
<p>This site is set up for testing the WebP Safe Migrator plugin.</p>
<h3>Test Instructions:</h3>
<ol>
<li>Go to <strong>Media → WebP Migrator</strong></li>
<li>Configure quality (recommended: 75)</li>
<li>Set batch size (start with 5-10)</li>
<li>Enable validation mode</li>
<li>Click \"Process next batch\"</li>
<li>Review converted images</li>
<li>Commit changes when satisfied</li>
</ol>
<p><strong>Admin Credentials:</strong><br>
Username: $ADMIN_USER<br>
Password: $ADMIN_PASSWORD</p>"
        
        php "$WPCLI_PATH" post create \
            --post_type=page \
            --post_title="WebP Migrator Test Guide" \
            --post_content="$TEST_POST_CONTENT" \
            --post_status=publish
        
        # Set the test page as homepage
        PAGE_ID=$(php "$WPCLI_PATH" post list --post_type=page --field=ID --format=csv | head -1)
        if [[ -n "$PAGE_ID" ]]; then
            php "$WPCLI_PATH" option update show_on_front page
            php "$WPCLI_PATH" option update page_on_front "$PAGE_ID"
        fi
        
        # Create some sample images for testing
        log_info "Setting up test images..."
        TEST_IMAGES_DIR="$INSTALL_PATH/www/wp-content/uploads/test-images"
        mkdir -p "$TEST_IMAGES_DIR"
        
        # Create simple test images using ImageMagick if available
        if command -v convert >/dev/null 2>&1; then
            convert -size 800x600 xc:red "$TEST_IMAGES_DIR/sample1.jpg" 2>/dev/null || true
            convert -size 1200x800 xc:blue "$TEST_IMAGES_DIR/sample2.png" 2>/dev/null || true
            convert -size 400x300 xc:green "$TEST_IMAGES_DIR/sample3.gif" 2>/dev/null || true
            log_success "Test images created"
        else
            log_info "ImageMagick not available, skipping test image creation"
        fi
        
        echo ""
        log_success "=== FULLY AUTOMATED SETUP COMPLETE! ==="
        echo ""
        echo -e "${CYAN}🌐 WordPress Site: http://localhost:8080${NC}"
        echo -e "${CYAN}🔧 Admin Panel: http://localhost:8080/wp-admin${NC}"
        echo -e "${YELLOW}👤 Username: $ADMIN_USER${NC}"
        echo -e "${YELLOW}🔑 Password: $ADMIN_PASSWORD${NC}"
        echo -e "${YELLOW}📧 Email: $ADMIN_EMAIL${NC}"
        echo ""
        echo -e "${GREEN}🔌 WebP Safe Migrator plugin is installed and activated!${NC}"
        echo -e "${CYAN}📍 Go to Media → WebP Migrator to start testing${NC}"
        
    else
        log_error "WordPress installation failed"
        exit 1
    fi
    
else
    log_info "Automated installation skipped. Complete WordPress setup manually at http://localhost:8080"
fi

# Final instructions
echo ""
log_success "=== Setup Complete! ==="
echo "Installation path: $INSTALL_PATH"
echo ""

if [[ "$AUTO_INSTALL" == true ]]; then
    echo -e "${GREEN}✅ WordPress is fully configured and ready to use!${NC}"
    echo -e "${CYAN}🌐 Visit: http://localhost:8080${NC}"
    echo -e "${CYAN}🔧 Admin: http://localhost:8080/wp-admin${NC}"
    echo -e "${CYAN}🔌 WebP Migrator: Media → WebP Migrator${NC}"
else
    echo -e "${CYAN}Next steps:${NC}"
    echo "1. Start services: $INSTALL_PATH/scripts/start-services.sh"
    echo "2. Open http://localhost:8080 to set up WordPress"
    echo "3. Install plugin: $INSTALL_PATH/scripts/install-plugin.sh"
fi

echo ""
echo "See README.txt for detailed instructions."
