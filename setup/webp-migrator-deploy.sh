#!/bin/bash
# WebP Safe Migrator - Automated Deployment Script
# Handles all container setup, WordPress installation, and plugin configuration automatically
# Fixes all issues encountered during manual setup

set -e

# Configuration
INSTALL_PATH="${INSTALL_PATH:-$HOME/webp-migrator-test}"
HTTP_PORT="${HTTP_PORT:-8080}"
HTTPS_PORT="${HTTPS_PORT:-8443}"
MYSQL_PORT="${MYSQL_PORT:-3307}"  # Use non-privileged port by default
PHPMYADMIN_PORT="${PHPMYADMIN_PORT:-8081}"
CLEAN_START="${CLEAN_START:-false}"
SKIP_BROWSER="${SKIP_BROWSER:-false}"

# Container Configuration
NETWORK_NAME="webp-migrator-net"
WORDPRESS_CONTAINER="webp-migrator-wordpress"
DB_CONTAINER="webp-migrator-mysql"
PHPMYADMIN_CONTAINER="webp-migrator-phpmyadmin"
WPCLI_CONTAINER="webp-migrator-wpcli"

# Database Configuration
DB_NAME="wordpress_webp_test"
DB_USER="wordpress"
DB_PASSWORD="wordpress123"
DB_ROOT_PASSWORD="root123"

# WordPress Configuration
SITE_TITLE="WebP Migrator Test Site"
ADMIN_USER="admin"
ADMIN_PASSWORD="admin123"
ADMIN_EMAIL="admin@webp-test.local"

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
log_header() { echo -e "\n${BLUE}=== $1 ===${NC}"; }

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --install-path)
            INSTALL_PATH="$2"
            shift 2
            ;;
        --http-port)
            HTTP_PORT="$2"
            shift 2
            ;;
        --mysql-port)
            MYSQL_PORT="$2"
            shift 2
            ;;
        --clean-start)
            CLEAN_START="true"
            shift
            ;;
        --skip-browser)
            SKIP_BROWSER="true"
            shift
            ;;
        --help)
            echo "WebP Safe Migrator - Automated Deployment"
            echo ""
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --install-path PATH    Installation directory (default: ~/webp-migrator-test)"
            echo "  --http-port PORT       HTTP port (default: 8080)"
            echo "  --mysql-port PORT      MySQL port (default: 3307)"
            echo "  --clean-start          Remove existing containers first"
            echo "  --skip-browser         Don't open browser automatically"
            echo "  --help                 Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0                           # Default deployment"
            echo "  $0 --clean-start             # Clean deployment"
            echo "  $0 --http-port 9080          # Custom HTTP port"
            exit 0
            ;;
        *)
            log_error "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# Detect container engine
detect_container_engine() {
    if command -v podman >/dev/null 2>&1; then
        CONTAINER_ENGINE="podman"
        log_info "Using Podman container engine"
    elif command -v docker >/dev/null 2>&1; then
        CONTAINER_ENGINE="docker"
        log_info "Using Docker container engine"
    else
        log_error "No container engine found. Please install Podman or Docker."
        echo ""
        echo "Install Podman (recommended): https://podman.io/getting-started/installation"
        echo "Install Docker: https://docs.docker.com/get-docker/"
        return 1
    fi
    
    # Test container engine
    if ! $CONTAINER_ENGINE info >/dev/null 2>&1; then
        log_error "$CONTAINER_ENGINE is not running or not accessible"
        if [[ "$CONTAINER_ENGINE" == "docker" ]]; then
            log_info "Make sure Docker daemon is running"
        fi
        return 1
    fi
    
    log_success "$CONTAINER_ENGINE is available and working"
    return 0
}

# Remove existing containers
remove_existing_containers() {
    log_header "Cleaning Up Existing Containers"
    
    local containers=("$WORDPRESS_CONTAINER" "$DB_CONTAINER" "$PHPMYADMIN_CONTAINER" "$WPCLI_CONTAINER")
    
    for container in "${containers[@]}"; do
        log_info "Removing container: $container"
        $CONTAINER_ENGINE rm -f "$container" >/dev/null 2>&1 && log_success "Removed: $container" || true
    done
    
    # Remove network
    log_info "Removing network: $NETWORK_NAME"
    $CONTAINER_ENGINE network rm "$NETWORK_NAME" >/dev/null 2>&1 || true
    
    log_success "Cleanup completed"
}

# Create container network
create_network() {
    log_header "Creating Container Network"
    
    log_info "Creating network: $NETWORK_NAME"
    if $CONTAINER_ENGINE network create "$NETWORK_NAME" >/dev/null 2>&1; then
        log_success "Network created: $NETWORK_NAME"
        return 0
    else
        # Check if network already exists
        if $CONTAINER_ENGINE network ls | grep -q "$NETWORK_NAME"; then
            log_success "Network already exists: $NETWORK_NAME"
            return 0
        else
            log_error "Failed to create network: $NETWORK_NAME"
            return 1
        fi
    fi
}

# Start database container
start_database_container() {
    log_header "Starting Database Container"
    
    log_info "Starting MySQL database container..."
    
    $CONTAINER_ENGINE run -d \
        --name "$DB_CONTAINER" \
        --network "$NETWORK_NAME" \
        -p "$MYSQL_PORT:3306" \
        -e MYSQL_DATABASE="$DB_NAME" \
        -e MYSQL_USER="$DB_USER" \
        -e MYSQL_PASSWORD="$DB_PASSWORD" \
        -e MYSQL_ROOT_PASSWORD="$DB_ROOT_PASSWORD" \
        -e MYSQL_INITDB_SKIP_TZINFO=1 \
        --restart unless-stopped \
        docker.io/library/mysql:8.0 \
        --default-authentication-plugin=mysql_native_password \
        --character-set-server=utf8mb4 \
        --collation-server=utf8mb4_unicode_ci
    
    if [[ $? -eq 0 ]]; then
        log_success "Database container started"
        
        # Wait for database to be ready
        log_info "Waiting for database to initialize..."
        local max_attempts=30
        local attempt=0
        
        while [[ $attempt -lt $max_attempts ]]; do
            attempt=$((attempt + 1))
            log_info "Checking database readiness (attempt $attempt/$max_attempts)..."
            
            if $CONTAINER_ENGINE exec "$DB_CONTAINER" mysqladmin ping -u root -p"$DB_ROOT_PASSWORD" >/dev/null 2>&1; then
                log_success "Database is ready"
                return 0
            fi
            
            sleep 2
        done
        
        log_error "Database failed to start within $((max_attempts * 2)) seconds"
        return 1
    else
        log_error "Failed to start database container"
        return 1
    fi
}

# Start WordPress container
start_wordpress_container() {
    log_header "Starting WordPress Container"
    
    log_info "Starting WordPress container with plugin mounted..."
    
    # Get absolute path to plugin source
    local script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    local plugin_source_path="$(dirname "$script_dir")/src"
    
    if [[ ! -d "$plugin_source_path" ]]; then
        log_error "Plugin source directory not found: $plugin_source_path"
        return 1
    fi
    
    # Create uploads directory
    mkdir -p "$INSTALL_PATH/uploads"
    
    $CONTAINER_ENGINE run -d \
        --name "$WORDPRESS_CONTAINER" \
        --network "$NETWORK_NAME" \
        -p "$HTTP_PORT:80" \
        -e WORDPRESS_DB_HOST="$DB_CONTAINER" \
        -e WORDPRESS_DB_USER="$DB_USER" \
        -e WORDPRESS_DB_PASSWORD="$DB_PASSWORD" \
        -e WORDPRESS_DB_NAME="$DB_NAME" \
        -e WORDPRESS_DEBUG=1 \
        -e "WORDPRESS_CONFIG_EXTRA=define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false); define('SCRIPT_DEBUG', true); define('WP_MEMORY_LIMIT', '512M'); define('FS_METHOD', 'direct');" \
        -v "$plugin_source_path:/var/www/html/wp-content/plugins/webp-safe-migrator" \
        -v "$INSTALL_PATH/uploads:/var/www/html/wp-content/uploads" \
        --restart unless-stopped \
        docker.io/library/wordpress:latest
    
    if [[ $? -eq 0 ]]; then
        log_success "WordPress container started"
        log_info "Plugin mounted from: $plugin_source_path"
        return 0
    else
        log_error "Failed to start WordPress container"
        return 1
    fi
}

# Start phpMyAdmin container
start_phpmyadmin_container() {
    log_header "Starting phpMyAdmin Container"
    
    log_info "Starting phpMyAdmin container..."
    
    $CONTAINER_ENGINE run -d \
        --name "$PHPMYADMIN_CONTAINER" \
        --network "$NETWORK_NAME" \
        -p "$PHPMYADMIN_PORT:80" \
        -e PMA_HOST="$DB_CONTAINER" \
        -e PMA_USER=root \
        -e PMA_PASSWORD="$DB_ROOT_PASSWORD" \
        -e PMA_ARBITRARY=1 \
        -e UPLOAD_LIMIT=100M \
        --restart unless-stopped \
        docker.io/library/phpmyadmin:latest
    
    if [[ $? -eq 0 ]]; then
        log_success "phpMyAdmin container started"
        return 0
    else
        log_error "Failed to start phpMyAdmin container"
        return 1
    fi
}

# Start WP-CLI container
start_wpcli_container() {
    log_header "Starting WP-CLI Container"
    
    log_info "Starting WP-CLI container..."
    
    local script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    local plugin_source_path="$(dirname "$script_dir")/src"
    
    $CONTAINER_ENGINE run -d \
        --name "$WPCLI_CONTAINER" \
        --network "$NETWORK_NAME" \
        -e WORDPRESS_DB_HOST="$DB_CONTAINER" \
        -e WORDPRESS_DB_USER="$DB_USER" \
        -e WORDPRESS_DB_PASSWORD="$DB_PASSWORD" \
        -e WORDPRESS_DB_NAME="$DB_NAME" \
        -v "wordpress_data:/var/www/html" \
        -v "$plugin_source_path:/var/www/html/wp-content/plugins/webp-safe-migrator" \
        --entrypoint "" \
        --restart unless-stopped \
        docker.io/library/wordpress:cli \
        tail -f /dev/null
    
    if [[ $? -eq 0 ]]; then
        log_success "WP-CLI container started"
        return 0
    else
        log_error "Failed to start WP-CLI container"
        return 1
    fi
}

# Wait for WordPress to be ready
wait_for_wordpress() {
    log_header "Waiting for WordPress to be Ready"
    
    local max_attempts=30
    local attempt=0
    local wordpress_url="http://localhost:$HTTP_PORT"
    
    log_info "Checking WordPress availability at: $wordpress_url"
    
    while [[ $attempt -lt $max_attempts ]]; do
        attempt=$((attempt + 1))
        log_info "Testing WordPress (attempt $attempt/$max_attempts)..."
        
        if curl -s -o /dev/null -w "%{http_code}" "$wordpress_url" | grep -q "200\|30[0-9]"; then
            log_success "WordPress is accessible"
            return 0
        fi
        
        sleep 2
    done
    
    log_warning "WordPress may not be fully ready, but continuing..."
    return 0
}

# Install WordPress
install_wordpress() {
    log_header "Installing WordPress"
    
    log_info "Installing WordPress core..."
    
    local wordpress_url="http://localhost:$HTTP_PORT"
    
    if $CONTAINER_ENGINE exec "$WPCLI_CONTAINER" wp core install \
        --url="$wordpress_url" \
        --title="$SITE_TITLE" \
        --admin_user="$ADMIN_USER" \
        --admin_password="$ADMIN_PASSWORD" \
        --admin_email="$ADMIN_EMAIL" \
        --locale="en_US" \
        --skip-email \
        --allow-root; then
        
        log_success "WordPress core installed successfully"
        return 0
    else
        log_error "Failed to install WordPress core"
        return 1
    fi
}

# Install WebP Migrator plugin
install_webp_migrator_plugin() {
    log_header "Installing WebP Safe Migrator Plugin"
    
    log_info "Activating WebP Safe Migrator plugin..."
    
    if $CONTAINER_ENGINE exec "$WPCLI_CONTAINER" wp plugin activate webp-safe-migrator --allow-root; then
        log_success "WebP Safe Migrator plugin activated"
        
        # Create sample content for testing
        log_info "Creating sample content..."
        $CONTAINER_ENGINE exec "$WPCLI_CONTAINER" wp post create \
            --post_type=page \
            --post_title="WebP Migrator Test Guide" \
            --post_content="<h2>Welcome to WebP Safe Migrator Test Site</h2><p>This site is set up for testing the WebP Safe Migrator plugin.</p><h3>Quick Start:</h3><ol><li>Go to <strong>Media → WebP Migrator</strong></li><li>Upload some test images</li><li>Configure quality settings (recommended: 75)</li><li>Set batch size (start with 5-10)</li><li>Click 'Process next batch'</li><li>Review converted images</li></ol><p><strong>Admin Credentials:</strong><br>Username: $ADMIN_USER<br>Password: $ADMIN_PASSWORD</p>" \
            --post_status=publish \
            --allow-root >/dev/null 2>&1 || true
        
        return 0
    else
        log_error "Failed to activate WebP Safe Migrator plugin"
        return 1
    fi
}

# Show access information
show_access_information() {
    log_header "🎉 Deployment Complete!"
    
    local wordpress_url="http://localhost:$HTTP_PORT"
    local admin_url="$wordpress_url/wp-admin"
    local phpmyadmin_url="http://localhost:$PHPMYADMIN_PORT"
    
    echo ""
    echo -e "${CYAN}📍 Access URLs:${NC}"
    echo -e "   🌐 WordPress Site: ${GREEN}$wordpress_url${NC}"
    echo -e "   🔧 WordPress Admin: ${GREEN}$admin_url${NC}"
    echo -e "   🗄️  phpMyAdmin: ${GREEN}$phpmyadmin_url${NC}"
    echo ""
    echo -e "${CYAN}🔑 WordPress Login Credentials:${NC}"
    echo -e "   👤 Username: ${YELLOW}$ADMIN_USER${NC}"
    echo -e "   🔑 Password: ${YELLOW}$ADMIN_PASSWORD${NC}"
    echo -e "   📧 Email: $ADMIN_EMAIL"
    echo ""
    echo -e "${CYAN}🗄️  Database Information:${NC}"
    echo -e "   📊 Database: $DB_NAME"
    echo -e "   👤 DB User: $DB_USER"
    echo -e "   🔑 DB Password: ${YELLOW}$DB_PASSWORD${NC}"
    echo -e "   🔑 Root Password: ${YELLOW}$DB_ROOT_PASSWORD${NC}"
    echo ""
    echo -e "${CYAN}🔌 Plugin Information:${NC}"
    echo -e "   ✅ WebP Safe Migrator plugin is installed and activated!"
    echo -e "   🎯 Access plugin at: ${GREEN}Media → WebP Migrator${NC}"
    echo ""
    echo -e "${CYAN}🛠️  Container Management:${NC}"
    echo -e "   Check status: ${GREEN}$CONTAINER_ENGINE ps${NC}"
    echo -e "   Stop all: $CONTAINER_ENGINE stop $WORDPRESS_CONTAINER $DB_CONTAINER $PHPMYADMIN_CONTAINER $WPCLI_CONTAINER"
    echo -e "   Start all: $CONTAINER_ENGINE start $DB_CONTAINER $WORDPRESS_CONTAINER $PHPMYADMIN_CONTAINER $WPCLI_CONTAINER"
    echo ""
    
    if [[ "$SKIP_BROWSER" != "true" ]]; then
        log_info "Opening WordPress in your browser..."
        sleep 2
        if command -v xdg-open >/dev/null 2>&1; then
            xdg-open "$wordpress_url" >/dev/null 2>&1 &
        elif command -v open >/dev/null 2>&1; then
            open "$wordpress_url" >/dev/null 2>&1 &
        else
            log_info "Please open $wordpress_url in your browser"
        fi
    fi
}

# Show container status
show_container_status() {
    log_header "Container Status"
    
    log_info "Current container status:"
    $CONTAINER_ENGINE ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
}

# Main deployment function
main() {
    log_header "WebP Safe Migrator - Automated Deployment"
    echo "This script will create a complete WordPress development environment"
    echo "with the WebP Safe Migrator plugin pre-installed and configured."
    echo ""
    
    # Check prerequisites
    if ! detect_container_engine; then
        return 1
    fi
    
    # Create installation directory
    log_info "Creating installation directory: $INSTALL_PATH"
    mkdir -p "$INSTALL_PATH/uploads"
    
    # Handle existing containers
    if [[ "$CLEAN_START" == "true" ]]; then
        remove_existing_containers
    else
        # Check if containers already exist
        local existing_containers=()
        for container in "$WORDPRESS_CONTAINER" "$DB_CONTAINER" "$PHPMYADMIN_CONTAINER" "$WPCLI_CONTAINER"; do
            if $CONTAINER_ENGINE ps -a --format "{{.Names}}" | grep -q "^$container$"; then
                existing_containers+=("$container")
            fi
        done
        
        if [[ ${#existing_containers[@]} -gt 0 ]]; then
            log_warning "Found existing containers: ${existing_containers[*]}"
            log_warning "Use --clean-start parameter to automatically remove them"
            echo -n "Remove existing containers? (y/N): "
            read -r response
            if [[ "$response" == "y" ]] || [[ "$response" == "Y" ]]; then
                remove_existing_containers
            else
                log_error "Cannot proceed with existing containers"
                return 1
            fi
        fi
    fi
    
    # Deploy containers
    create_network || return 1
    start_database_container || return 1
    start_wordpress_container || return 1
    start_phpmyadmin_container || return 1
    start_wpcli_container || return 1
    
    # Wait for services and install WordPress
    wait_for_wordpress
    install_wordpress || return 1
    install_webp_migrator_plugin || return 1
    
    # Show results
    show_container_status
    show_access_information
    
    return 0
}

# Execute main deployment
if main; then
    log_success "🎉 WebP Safe Migrator deployment completed successfully!"
    exit 0
else
    log_error "❌ Deployment failed!"
    echo "Run with --clean-start parameter to clean up and retry"
    exit 1
fi
