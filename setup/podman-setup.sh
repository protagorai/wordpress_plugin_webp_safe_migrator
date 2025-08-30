#!/bin/bash
# WebP Safe Migrator - Podman Development Environment
# Cross-platform WordPress development using Podman containers (Docker alternative)
# Works on Windows (WSL), Linux, and macOS

set -e

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

# Default configuration
INSTALL_PATH="$HOME/webp-migrator-test"
NETWORK_NAME="webp-migrator-net"
VOLUME_PREFIX="webp-migrator"

# Container names
WORDPRESS_CONTAINER="webp-wordpress"
DB_CONTAINER="webp-mysql"
PHPMYADMIN_CONTAINER="webp-phpmyadmin"

# Detect if we're on Windows (WSL)
detect_platform() {
    if [[ -n "$WSL_DISTRO_NAME" ]] || [[ "$(uname -r)" == *microsoft* ]]; then
        PLATFORM="wsl"
        log_info "Detected Windows WSL environment"
    elif [[ "$OSTYPE" == "darwin"* ]]; then
        PLATFORM="macos"
        log_info "Detected macOS environment"
    else
        PLATFORM="linux"
        log_info "Detected Linux environment"
    fi
}

# Check dependencies
check_dependencies() {
    log_info "Checking dependencies..."
    
    # Check for Podman
    if ! command -v podman >/dev/null 2>&1; then
        log_error "Podman not found. Please install Podman first:"
        echo ""
        case $PLATFORM in
            "linux")
                echo "  Ubuntu/Debian: sudo apt-get install podman"
                echo "  CentOS/RHEL: sudo yum install podman"
                echo "  Arch: sudo pacman -S podman"
                ;;
            "macos")
                echo "  Homebrew: brew install podman"
                echo "  Then: podman machine init && podman machine start"
                ;;
            "wsl")
                echo "  Install Podman Desktop for Windows"
                echo "  Or use WSL: sudo apt-get install podman"
                ;;
        esac
        echo ""
        echo "Why Podman? It's fully open-source (Apache 2.0) with no licensing restrictions,"
        echo "unlike Docker Desktop which requires paid licenses for commercial use."
        exit 1
    fi
    
    # Check if Podman machine is running (macOS/Windows)
    if [[ "$PLATFORM" == "macos" ]] || [[ "$PLATFORM" == "wsl" ]]; then
        if ! podman machine list | grep -q "Currently running"; then
            log_info "Starting Podman machine..."
            podman machine start 2>/dev/null || {
                log_info "Initializing Podman machine..."
                podman machine init
                podman machine start
            }
        fi
    fi
    
    # Test Podman functionality
    if ! podman info >/dev/null 2>&1; then
        log_error "Podman is not working properly. Please check your installation."
        exit 1
    fi
    
    log_success "Podman is available and working"
}

# Show usage
show_usage() {
    echo "WebP Safe Migrator - Podman Development Environment"
    echo ""
    echo "Usage: $0 ACTION [OPTIONS]"
    echo ""
    echo "Actions:"
    echo "  up           Start all containers"
    echo "  down         Stop all containers"
    echo "  restart      Restart all containers"
    echo "  logs         Show container logs"
    echo "  status       Show container status"
    echo "  shell        Open shell in WordPress container"
    echo "  wp           Execute WP-CLI commands"
    echo "  mysql        Open MySQL shell"
    echo "  install      Install WordPress and plugin"
    echo "  clean        Remove all containers and volumes"
    echo "  backup       Create backup of WordPress data"
    echo "  restore      Restore from backup"
    echo ""
    echo "Options:"
    echo "  --detach     Run containers in background (default for up)"
    echo "  --follow     Follow logs (for logs action)"
    echo "  --path PATH  Installation path (default: ~/webp-migrator-test)"
    echo "  --help       Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 up                    # Start environment"
    echo "  $0 wp plugin list        # List WordPress plugins"
    echo "  $0 logs --follow         # Watch logs"
    echo "  $0 shell                 # Open container shell"
    echo ""
    echo "Advantages of Podman over Docker:"
    echo "  • No licensing restrictions (Apache 2.0 license)"
    echo "  • Rootless containers (better security)"
    echo "  • No daemon required"
    echo "  • Compatible with Docker commands"
}

# Parse arguments
ACTION="$1"
shift || true

DETACH=true
FOLLOW=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --detach)
            DETACH=true
            shift
            ;;
        --no-detach)
            DETACH=false
            shift
            ;;
        --follow)
            FOLLOW=true
            shift
            ;;
        --path)
            INSTALL_PATH="$2"
            shift 2
            ;;
        --help)
            show_usage
            exit 0
            ;;
        *)
            # Assume remaining args are for the action
            break
            ;;
    esac
done

if [[ -z "$ACTION" ]]; then
    show_usage
    exit 1
fi

# Setup paths
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_DIR="$(dirname "$SCRIPT_DIR")/src"

# Create network if it doesn't exist
create_network() {
    if ! podman network exists "$NETWORK_NAME" 2>/dev/null; then
        log_info "Creating Podman network: $NETWORK_NAME"
        podman network create "$NETWORK_NAME"
    fi
}

# Start database container
start_database() {
    log_info "Starting MySQL database container..."
    
    # Remove existing container if it exists
    podman rm -f "$DB_CONTAINER" 2>/dev/null || true
    
    podman run -d \
        --name "$DB_CONTAINER" \
        --network "$NETWORK_NAME" \
        -p 3306:3306 \
        -e MYSQL_DATABASE=wordpress_webp_test \
        -e MYSQL_USER=wordpress \
        -e MYSQL_PASSWORD=wordpress123 \
        -e MYSQL_ROOT_PASSWORD=root123 \
        -v "${VOLUME_PREFIX}-db-data:/var/lib/mysql" \
        --restart unless-stopped \
        docker.io/library/mysql:8.0 \
        --default-authentication-plugin=mysql_native_password
    
    # Wait for database to be ready
    log_info "Waiting for database to be ready..."
    for i in {1..30}; do
        if podman exec "$DB_CONTAINER" mysql -u root -proot123 -e "SELECT 1;" >/dev/null 2>&1; then
            log_success "Database is ready"
            return 0
        fi
        sleep 2
    done
    
    log_error "Database failed to start within 60 seconds"
    return 1
}

# Start WordPress container
start_wordpress() {
    log_info "Starting WordPress container..."
    
    # Remove existing container if it exists
    podman rm -f "$WORDPRESS_CONTAINER" 2>/dev/null || true
    
    # Ensure source directory exists and is accessible
    mkdir -p "$INSTALL_PATH/plugin-dev"
    if [[ -d "$SOURCE_DIR" ]]; then
        cp -r "$SOURCE_DIR/"* "$INSTALL_PATH/plugin-dev/"
    fi
    
    podman run -d \
        --name "$WORDPRESS_CONTAINER" \
        --network "$NETWORK_NAME" \
        -p 8080:80 \
        -e WORDPRESS_DB_HOST="$DB_CONTAINER" \
        -e WORDPRESS_DB_USER=wordpress \
        -e WORDPRESS_DB_PASSWORD=wordpress123 \
        -e WORDPRESS_DB_NAME=wordpress_webp_test \
        -e WORDPRESS_DEBUG=1 \
        -e WORDPRESS_CONFIG_EXTRA="define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false); define('SCRIPT_DEBUG', true); define('WP_MEMORY_LIMIT', '512M'); define('FS_METHOD', 'direct');" \
        -v "${VOLUME_PREFIX}-wp-data:/var/www/html" \
        -v "$INSTALL_PATH/plugin-dev:/var/www/html/wp-content/plugins/webp-safe-migrator" \
        --restart unless-stopped \
        docker.io/library/wordpress:latest
    
    # Wait for WordPress to be ready
    log_info "Waiting for WordPress to be ready..."
    for i in {1..30}; do
        if curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080" | grep -q "200\|30[0-9]"; then
            log_success "WordPress is ready"
            return 0
        fi
        sleep 2
    done
    
    log_warning "WordPress may not be fully ready yet, but continuing..."
    return 0
}

# Start phpMyAdmin container
start_phpmyadmin() {
    log_info "Starting phpMyAdmin container..."
    
    # Remove existing container if it exists
    podman rm -f "$PHPMYADMIN_CONTAINER" 2>/dev/null || true
    
    podman run -d \
        --name "$PHPMYADMIN_CONTAINER" \
        --network "$NETWORK_NAME" \
        -p 8081:80 \
        -e PMA_HOST="$DB_CONTAINER" \
        -e PMA_USER=root \
        -e PMA_PASSWORD=root123 \
        -e PMA_ARBITRARY=1 \
        --restart unless-stopped \
        docker.io/phpmyadmin:latest
    
    log_success "phpMyAdmin started"
}

# Install WP-CLI in WordPress container
install_wpcli_in_container() {
    log_info "Installing WP-CLI in WordPress container..."
    
    podman exec "$WORDPRESS_CONTAINER" bash -c "
        if [[ ! -f /usr/local/bin/wp ]]; then
            curl -o /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
            chmod +x /tmp/wp-cli.phar
            mv /tmp/wp-cli.phar /usr/local/bin/wp
        fi
    "
    
    log_success "WP-CLI installed in container"
}

# Main execution
case $ACTION in
    up)
        detect_platform
        check_dependencies
        
        log_info "Starting WebP Migrator Podman environment..."
        
        # Create necessary directories
        mkdir -p "$INSTALL_PATH"/{plugin-dev,uploads,backups}
        
        create_network
        start_database
        start_wordpress
        start_phpmyadmin
        install_wpcli_in_container
        
        log_success "Environment started successfully!"
        echo ""
        echo -e "${CYAN}📍 Quick Access:${NC}"
        echo "   WordPress: http://localhost:8080"
        echo "   Admin: http://localhost:8080/wp-admin"
        echo "   phpMyAdmin: http://localhost:8081"
        echo ""
        echo -e "${CYAN}🔑 Database Credentials:${NC}"
        echo "   Database: wordpress_webp_test"
        echo "   Username: wordpress"
        echo "   Password: wordpress123"
        echo "   Root Password: root123"
        echo ""
        echo -e "${CYAN}🔧 Management:${NC}"
        echo "   View logs: $0 logs --follow"
        echo "   WP-CLI: $0 wp plugin list"
        echo "   Shell: $0 shell"
        echo ""
        echo -e "${YELLOW}💡 Plugin Development:${NC}"
        echo "   Edit files in: src/ (auto-synced to container)"
        echo "   Plugin location: $INSTALL_PATH/plugin-dev/"
        ;;
        
    down)
        log_info "Stopping WebP Migrator Podman environment..."
        
        podman stop "$WORDPRESS_CONTAINER" "$DB_CONTAINER" "$PHPMYADMIN_CONTAINER" 2>/dev/null || true
        podman rm "$WORDPRESS_CONTAINER" "$DB_CONTAINER" "$PHPMYADMIN_CONTAINER" 2>/dev/null || true
        
        log_success "Environment stopped"
        ;;
        
    restart)
        log_info "Restarting WebP Migrator Podman environment..."
        
        podman restart "$WORDPRESS_CONTAINER" "$DB_CONTAINER" "$PHPMYADMIN_CONTAINER" 2>/dev/null || true
        
        log_success "Environment restarted"
        ;;
        
    logs)
        if [[ "$FOLLOW" == true ]]; then
            podman logs -f "$WORDPRESS_CONTAINER"
        else
            echo -e "${CYAN}=== WordPress Logs ===${NC}"
            podman logs --tail 20 "$WORDPRESS_CONTAINER"
            echo ""
            echo -e "${CYAN}=== Database Logs ===${NC}"
            podman logs --tail 10 "$DB_CONTAINER"
        fi
        ;;
        
    status)
        echo -e "${CYAN}=== Container Status ===${NC}"
        
        # Check container status
        for container in "$WORDPRESS_CONTAINER" "$DB_CONTAINER" "$PHPMYADMIN_CONTAINER"; do
            if podman ps --format "{{.Names}}" | grep -q "^$container$"; then
                echo -e "$container: ${GREEN}✓ Running${NC}"
            else
                echo -e "$container: ${RED}✗ Stopped${NC}"
            fi
        done
        
        echo ""
        echo -e "${CYAN}=== Service Health ===${NC}"
        
        # Check WordPress
        if curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080" | grep -q "200\|30[0-9]"; then
            echo -e "WordPress: ${GREEN}✓ Accessible${NC} (http://localhost:8080)"
        else
            echo -e "WordPress: ${RED}✗ Not accessible${NC}"
        fi
        
        # Check phpMyAdmin
        if curl -s -o /dev/null -w "%{http_code}" "http://localhost:8081" | grep -q "200\|30[0-9]"; then
            echo -e "phpMyAdmin: ${GREEN}✓ Accessible${NC} (http://localhost:8081)"
        else
            echo -e "phpMyAdmin: ${RED}✗ Not accessible${NC}"
        fi
        
        # Check database
        if podman exec "$DB_CONTAINER" mysql -u wordpress -pwordpress123 -e "SELECT 1;" wordpress_webp_test >/dev/null 2>&1; then
            echo -e "Database: ${GREEN}✓ Connected${NC}"
        else
            echo -e "Database: ${RED}✗ Connection failed${NC}"
        fi
        
        # Check plugin files
        if [[ -d "$INSTALL_PATH/plugin-dev" ]] && [[ -n "$(ls -A "$INSTALL_PATH/plugin-dev" 2>/dev/null)" ]]; then
            echo -e "Plugin Files: ${GREEN}✓ Available${NC}"
        else
            echo -e "Plugin Files: ${YELLOW}⚠ Not found${NC}"
        fi
        ;;
        
    shell)
        log_info "Opening shell in WordPress container..."
        podman exec -it "$WORDPRESS_CONTAINER" bash
        ;;
        
    wp)
        log_info "Executing WP-CLI command: $*"
        podman exec "$WORDPRESS_CONTAINER" wp "$@" --allow-root
        ;;
        
    mysql)
        log_info "Opening MySQL shell..."
        podman exec -it "$DB_CONTAINER" mysql -u root -proot123 wordpress_webp_test
        ;;
        
    install)
        log_info "Installing WordPress and WebP Safe Migrator plugin..."
        
        # Ensure containers are running
        if ! podman ps --format "{{.Names}}" | grep -q "^$WORDPRESS_CONTAINER$"; then
            log_error "WordPress container not running. Run '$0 up' first."
            exit 1
        fi
        
        # Wait for database
        log_info "Waiting for database to be ready..."
        for i in {1..30}; do
            if podman exec "$DB_CONTAINER" mysql -u wordpress -pwordpress123 -e "SELECT 1;" wordpress_webp_test >/dev/null 2>&1; then
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
        podman exec "$WORDPRESS_CONTAINER" wp core install \
            --url="http://localhost:8080" \
            --title="WebP Migrator Test Site" \
            --admin_user="admin" \
            --admin_password="admin123" \
            --admin_email="admin@webp-test.local" \
            --skip-email \
            --allow-root
        
        # Activate plugin
        log_info "Activating WebP Safe Migrator plugin..."
        podman exec "$WORDPRESS_CONTAINER" wp plugin activate webp-safe-migrator --allow-root
        
        # Set up some test content
        log_info "Creating test content..."
        podman exec "$WORDPRESS_CONTAINER" wp post create \
            --post_type=page \
            --post_title="WebP Migrator Test Guide" \
            --post_content="<h2>Welcome to WebP Safe Migrator Test Site</h2><p>Go to Media → WebP Migrator to start testing.</p>" \
            --post_status=publish \
            --allow-root
        
        log_success "WordPress and plugin installed successfully!"
        echo ""
        echo -e "${CYAN}🌐 WordPress: http://localhost:8080${NC}"
        echo -e "${CYAN}🔧 Admin: http://localhost:8080/wp-admin${NC}"
        echo -e "${YELLOW}👤 Username: admin${NC}"
        echo -e "${YELLOW}🔑 Password: admin123${NC}"
        ;;
        
    clean)
        log_warning "This will remove all containers and data!"
        echo -n "Are you sure? (y/N): "
        read -r confirm
        if [[ "$confirm" == "y" ]] || [[ "$confirm" == "Y" ]]; then
            log_info "Removing containers and volumes..."
            
            # Stop and remove containers
            podman stop "$WORDPRESS_CONTAINER" "$DB_CONTAINER" "$PHPMYADMIN_CONTAINER" 2>/dev/null || true
            podman rm "$WORDPRESS_CONTAINER" "$DB_CONTAINER" "$PHPMYADMIN_CONTAINER" 2>/dev/null || true
            
            # Remove volumes
            podman volume rm "${VOLUME_PREFIX}-wp-data" "${VOLUME_PREFIX}-db-data" 2>/dev/null || true
            
            # Remove network
            podman network rm "$NETWORK_NAME" 2>/dev/null || true
            
            # Clean up local directories
            rm -rf "$INSTALL_PATH/plugin-dev" "$INSTALL_PATH/uploads" 2>/dev/null || true
            
            log_success "Environment cleaned"
        else
            log_info "Clean operation cancelled"
        fi
        ;;
        
    backup)
        log_info "Creating backup of WordPress data..."
        BACKUP_DIR="$INSTALL_PATH/backups/podman-$(date +%Y%m%d-%H%M%S)"
        mkdir -p "$BACKUP_DIR"
        
        # Backup database
        log_info "Backing up database..."
        podman exec "$DB_CONTAINER" mysqldump -u root -proot123 wordpress_webp_test > "$BACKUP_DIR/database.sql"
        
        # Backup WordPress files
        log_info "Backing up WordPress files..."
        podman exec "$WORDPRESS_CONTAINER" tar -czf /tmp/wordpress-backup.tar.gz -C /var/www/html wp-content
        podman cp "$WORDPRESS_CONTAINER:/tmp/wordpress-backup.tar.gz" "$BACKUP_DIR/"
        
        # Backup plugin development files
        if [[ -d "$INSTALL_PATH/plugin-dev" ]]; then
            tar -czf "$BACKUP_DIR/plugin-dev.tar.gz" -C "$INSTALL_PATH" plugin-dev
        fi
        
        log_success "Backup created at: $BACKUP_DIR"
        ;;
        
    restore)
        log_info "Restoring from backup..."
        
        if [[ ! -d "$INSTALL_PATH/backups" ]]; then
            log_error "No backups directory found"
            exit 1
        fi
        
        # List available backups
        BACKUPS=($(find "$INSTALL_PATH/backups" -type d -name "podman-*" | sort -r))
        
        if [[ ${#BACKUPS[@]} -eq 0 ]]; then
            log_error "No backups found"
            exit 1
        fi
        
        echo "Available backups:"
        for i in "${!BACKUPS[@]}"; do
            BACKUP_NAME=$(basename "${BACKUPS[$i]}")
            echo "  $((i+1)). $BACKUP_NAME"
        done
        
        echo -n "Select backup to restore (1-${#BACKUPS[@]}): "
        read -r selection
        
        if ! [[ "$selection" =~ ^[0-9]+$ ]] || [[ $selection -lt 1 ]] || [[ $selection -gt ${#BACKUPS[@]} ]]; then
            log_error "Invalid selection"
            exit 1
        fi
        
        SELECTED_BACKUP="${BACKUPS[$((selection-1))]}"
        
        # Restore database
        if [[ -f "$SELECTED_BACKUP/database.sql" ]]; then
            log_info "Restoring database..."
            podman exec -i "$DB_CONTAINER" mysql -u root -proot123 wordpress_webp_test < "$SELECTED_BACKUP/database.sql"
        fi
        
        # Restore WordPress files
        if [[ -f "$SELECTED_BACKUP/wordpress-backup.tar.gz" ]]; then
            log_info "Restoring WordPress files..."
            podman cp "$SELECTED_BACKUP/wordpress-backup.tar.gz" "$WORDPRESS_CONTAINER:/tmp/"
            podman exec "$WORDPRESS_CONTAINER" tar -xzf /tmp/wordpress-backup.tar.gz -C /var/www/html
        fi
        
        # Restore plugin development files
        if [[ -f "$SELECTED_BACKUP/plugin-dev.tar.gz" ]]; then
            log_info "Restoring plugin development files..."
            tar -xzf "$SELECTED_BACKUP/plugin-dev.tar.gz" -C "$INSTALL_PATH"
        fi
        
        log_success "Restore completed"
        ;;
        
    *)
        log_error "Unknown action: $ACTION"
        show_usage
        exit 1
        ;;
esac
