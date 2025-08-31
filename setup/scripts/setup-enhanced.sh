#!/bin/bash
# WebP Safe Migrator - Enhanced Setup Script with Full Configuration Options
# Supports Docker/Podman, Apache/Nginx, SSL, custom domains, and more

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

# Default configuration
CONTAINER_ENGINE=""
WEB_SERVER="apache"
WORDPRESS_VERSION="latest"
PHP_VERSION="8.2"
CUSTOM_DOMAIN="localhost"
ENABLE_SSL="true"
USE_STANDARD_PORTS="true"
INSTALL_PATH="$(pwd)"
ACTION="up"

# Show usage
show_usage() {
    echo "WebP Safe Migrator - Enhanced Setup Script"
    echo ""
    echo "Usage: $0 [OPTIONS] [ACTION]"
    echo ""
    echo "Actions:"
    echo "  up           Start the development environment"
    echo "  down         Stop the development environment"
    echo "  install      Install WordPress and activate plugin"
    echo "  clean        Remove all containers and volumes"
    echo "  status       Show environment status"
    echo "  logs         Show container logs"
    echo "  shell        Open shell in WordPress container"
    echo "  wp           Execute WP-CLI commands"
    echo ""
    echo "Options:"
    echo "  --engine ENGINE        Container engine: docker, podman, auto (default: auto)"
    echo "  --webserver SERVER     Web server: apache, nginx (default: apache)"
    echo "  --wp-version VERSION   WordPress version (default: latest)"
    echo "  --php-version VERSION  PHP version (default: 8.2)"
    echo "  --domain DOMAIN        Custom domain (default: localhost)"
    echo "  --no-ssl              Disable SSL/HTTPS"
    echo "  --alt-ports           Use alternative ports (8080/8443 instead of 80/443)"
    echo "  --install-path PATH   Installation directory (default: current)"
    echo "  --help                Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 up                                    # Quick start with defaults"
    echo "  $0 --webserver nginx --domain webp.local up"
    echo "  $0 --engine podman --no-ssl up"
    echo "  $0 --wp-version 6.3 --php-version 8.1 up"
    echo "  $0 install                               # Install WordPress after 'up'"
    echo ""
    echo "Environment Variables:"
    echo "  You can also use .env file for configuration"
}

# Parse command line arguments
parse_args() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --engine)
                CONTAINER_ENGINE="$2"
                shift 2
                ;;
            --webserver)
                WEB_SERVER="$2"
                shift 2
                ;;
            --wp-version)
                WORDPRESS_VERSION="$2"
                shift 2
                ;;
            --php-version)
                PHP_VERSION="$2"
                shift 2
                ;;
            --domain)
                CUSTOM_DOMAIN="$2"
                shift 2
                ;;
            --no-ssl)
                ENABLE_SSL="false"
                shift
                ;;
            --alt-ports)
                USE_STANDARD_PORTS="false"
                shift
                ;;
            --install-path)
                INSTALL_PATH="$2"
                shift 2
                ;;
            --help)
                show_usage
                exit 0
                ;;
            up|down|install|clean|status|logs|shell|wp)
                ACTION="$1"
                shift
                break
                ;;
            *)
                if [[ "$1" =~ ^-- ]]; then
                    log_error "Unknown option: $1"
                    show_usage
                    exit 1
                else
                    ACTION="$1"
                    shift
                    break
                fi
                ;;
        esac
    done
    
    # Store remaining arguments for wp command
    WP_ARGS=("$@")
}

# Detect container engine
detect_container_engine() {
    if [[ "$CONTAINER_ENGINE" == "auto" ]] || [[ -z "$CONTAINER_ENGINE" ]]; then
        log_info "Auto-detecting container engine..."
        
        if command -v podman >/dev/null 2>&1; then
            CONTAINER_ENGINE="podman"
            log_success "Detected Podman (recommended for commercial use)"
        elif command -v docker >/dev/null 2>&1; then
            CONTAINER_ENGINE="docker"
            log_warning "Detected Docker (check licensing for commercial use)"
        else
            log_error "No container engine found. Please install Docker or Podman."
            echo ""
            echo "Installation guides:"
            echo "  Podman: https://podman.io/getting-started/installation"
            echo "  Docker: https://docs.docker.com/get-docker/"
            exit 1
        fi
    else
        if ! command -v "$CONTAINER_ENGINE" >/dev/null 2>&1; then
            log_error "Container engine '$CONTAINER_ENGINE' not found"
            exit 1
        fi
    fi
    
    log_info "Using container engine: $CONTAINER_ENGINE"
}

# Load environment file if exists
load_env() {
    if [[ -f "$INSTALL_PATH/.env" ]]; then
        log_info "Loading configuration from .env file"
        set -a
        source "$INSTALL_PATH/.env"
        set +a
    elif [[ -f "$INSTALL_PATH/setup/.env" ]]; then
        log_info "Loading configuration from setup/.env file"
        set -a
        source "$INSTALL_PATH/setup/.env"
        set +a
    fi
}

# Create environment file
create_env_file() {
    local env_file="$INSTALL_PATH/setup/.env"
    
    log_info "Creating environment configuration..."
    
    cat > "$env_file" << EOF
# WebP Safe Migrator - Generated Environment Configuration
WORDPRESS_VERSION=$WORDPRESS_VERSION
PHP_VERSION=$PHP_VERSION
CUSTOM_DOMAIN=$CUSTOM_DOMAIN
ENABLE_SSL=$ENABLE_SSL
CONTAINER_ENGINE=$CONTAINER_ENGINE
WEB_SERVER=$WEB_SERVER
EOF

    if [[ "$USE_STANDARD_PORTS" == "false" ]]; then
        cat >> "$env_file" << EOF
HTTP_PORT=8080
HTTPS_PORT=8443
MYSQL_PORT=3307
PHPMYADMIN_PORT=8082
EOF
    fi
    
    log_success "Environment configuration saved to $env_file"
}

# Get compose file based on configuration
get_compose_file() {
    local compose_file=""
    
    if [[ "$WEB_SERVER" == "nginx" ]]; then
        compose_file="docker-compose.nginx.yml"
    elif [[ "$CONTAINER_ENGINE" == "podman" ]]; then
        compose_file="podman-compose.yml"
    else
        compose_file="docker-compose.yml"
    fi
    
    echo "$compose_file"
}

# Get compose command
get_compose_command() {
    local compose_file="$1"
    local cmd=""
    
    if [[ "$CONTAINER_ENGINE" == "podman" ]]; then
        if command -v podman-compose >/dev/null 2>&1; then
            cmd="podman-compose -f $compose_file"
        else
            cmd="podman compose -f $compose_file"
        fi
    else
        if command -v docker-compose >/dev/null 2>&1; then
            cmd="docker-compose -f $compose_file"
        else
            cmd="docker compose -f $compose_file"
        fi
    fi
    
    echo "$cmd"
}

# Setup hosts file entry for custom domain
setup_hosts_entry() {
    if [[ "$CUSTOM_DOMAIN" != "localhost" ]] && [[ "$CUSTOM_DOMAIN" != "127.0.0.1" ]]; then
        log_info "Custom domain detected: $CUSTOM_DOMAIN"
        
        if ! grep -q "$CUSTOM_DOMAIN" /etc/hosts 2>/dev/null; then
            log_warning "Custom domain not found in /etc/hosts"
            echo ""
            echo "To use custom domain '$CUSTOM_DOMAIN', add this line to your /etc/hosts file:"
            echo "127.0.0.1 $CUSTOM_DOMAIN"
            echo ""
            echo "On Linux/macOS: sudo echo '127.0.0.1 $CUSTOM_DOMAIN' >> /etc/hosts"
            echo "On Windows: Add to C:\\Windows\\System32\\drivers\\etc\\hosts"
            echo ""
            read -p "Press Enter to continue..."
        else
            log_success "Custom domain found in /etc/hosts"
        fi
    fi
}

# Create required directories
create_directories() {
    log_info "Creating required directories..."
    
    mkdir -p "$INSTALL_PATH/setup/uploads" \
             "$INSTALL_PATH/setup/logs" \
             "$INSTALL_PATH/setup/ssl-certs"
    
    log_success "Directories created"
}

# Main action handlers
action_up() {
    log_header "Starting WebP Safe Migrator Development Environment"
    
    local compose_file=$(get_compose_file)
    local compose_cmd=$(get_compose_command "$compose_file")
    
    log_info "Configuration:"
    log_info "  Container Engine: $CONTAINER_ENGINE"
    log_info "  Web Server: $WEB_SERVER"
    log_info "  WordPress: $WORDPRESS_VERSION"
    log_info "  PHP: $PHP_VERSION"
    log_info "  Domain: $CUSTOM_DOMAIN"
    log_info "  SSL: $ENABLE_SSL"
    log_info "  Compose File: $compose_file"
    
    create_directories
    setup_hosts_entry
    create_env_file
    
    cd "$INSTALL_PATH/setup"
    
    log_info "Starting containers..."
    $compose_cmd up -d
    
    log_info "Waiting for services to be ready..."
    sleep 15
    
    # Check service health
    local http_port="80"
    local https_port="443"
    
    if [[ "$USE_STANDARD_PORTS" == "false" ]]; then
        http_port="8080"
        https_port="8443"
    fi
    
    local base_url="http://$CUSTOM_DOMAIN:$http_port"
    if [[ "$ENABLE_SSL" == "true" ]]; then
        base_url="https://$CUSTOM_DOMAIN:$https_port"
    fi
    
    for i in {1..30}; do
        if curl -k -s -o /dev/null -w "%{http_code}" "$base_url" | grep -q "200\|30[0-9]"; then
            break
        fi
        if [[ $i -eq 30 ]]; then
            log_warning "Services may not be fully ready yet"
        fi
        sleep 2
    done
    
    log_success "Environment started successfully!"
    echo ""
    log_header "Quick Access"
    echo "  WordPress: $base_url"
    echo "  Admin: $base_url/wp-admin"
    echo "  phpMyAdmin: http://$CUSTOM_DOMAIN:8081"
    echo ""
    log_header "Next Steps"
    echo "  1. Run: $0 install"
    echo "  2. Open: $base_url"
    echo "  3. Complete WordPress setup"
    echo ""
}

action_install() {
    log_header "Installing WordPress and WebP Safe Migrator Plugin"
    
    local compose_file=$(get_compose_file)
    local compose_cmd=$(get_compose_command "$compose_file")
    
    cd "$INSTALL_PATH/setup"
    
    # Ensure containers are running
    $compose_cmd up -d
    
    # Wait for database
    log_info "Waiting for database to be ready..."
    for i in {1..30}; do
        if $compose_cmd exec -T db mysql -u wordpress -pwordpress123 -e "SELECT 1;" wordpress_webp_test >/dev/null 2>&1; then
            break
        fi
        if [[ $i -eq 30 ]]; then
            log_error "Database not ready after 60 seconds"
            exit 1
        fi
        sleep 2
    done
    
    # Use the auto-installation script for consistent setup
    log_info "Running WordPress auto-installation..."
    
    local wp_url="http://$CUSTOM_DOMAIN"
    if [[ "$USE_STANDARD_PORTS" == "false" ]]; then
        wp_url="http://$CUSTOM_DOMAIN:8080"
    fi
    
    # Set environment variables for the auto-install script
    export WP_ADMIN_USER="admin"
    export WP_ADMIN_PASS="admin123"
    export WP_ADMIN_EMAIL="admin@$CUSTOM_DOMAIN"
    export WP_SITE_TITLE="WebP Migrator Development Site"
    export WP_SITE_URL="$wp_url"
    export CONTAINER_ENGINE="$CONTAINER_ENGINE"
    export COMPOSE_FILE="$(get_compose_file)"
    
    # Run the auto-installation script
    if [[ -x "./wp-auto-install.sh" ]]; then
        ./wp-auto-install.sh
    else
        log_error "WordPress auto-installation script not found or not executable"
        exit 1
    fi
}

action_down() {
    log_header "Stopping WebP Safe Migrator Development Environment"
    
    local compose_file=$(get_compose_file)
    local compose_cmd=$(get_compose_command "$compose_file")
    
    cd "$INSTALL_PATH/setup"
    $compose_cmd down
    
    log_success "Environment stopped"
}

action_clean() {
    log_header "Cleaning WebP Safe Migrator Development Environment"
    
    local compose_file=$(get_compose_file)
    local compose_cmd=$(get_compose_command "$compose_file")
    
    log_warning "This will remove all containers and data!"
    echo -n "Are you sure? (y/N): "
    read -r confirm
    
    if [[ "$confirm" == "y" ]] || [[ "$confirm" == "Y" ]]; then
        cd "$INSTALL_PATH/setup"
        $compose_cmd down -v --remove-orphans
        
        # Clean up additional resources
        if [[ "$CONTAINER_ENGINE" == "docker" ]]; then
            docker system prune -f
        elif [[ "$CONTAINER_ENGINE" == "podman" ]]; then
            podman system prune -f
        fi
        
        log_success "Environment cleaned"
    else
        log_info "Clean operation cancelled"
    fi
}

action_status() {
    log_header "WebP Safe Migrator Environment Status"
    
    local compose_file=$(get_compose_file)
    local compose_cmd=$(get_compose_command "$compose_file")
    
    cd "$INSTALL_PATH/setup"
    $compose_cmd ps
}

action_logs() {
    local compose_file=$(get_compose_file)
    local compose_cmd=$(get_compose_command "$compose_file")
    
    cd "$INSTALL_PATH/setup"
    $compose_cmd logs -f
}

action_shell() {
    local compose_file=$(get_compose_file)
    local compose_cmd=$(get_compose_command "$compose_file")
    
    cd "$INSTALL_PATH/setup"
    $compose_cmd exec wordpress bash
}

action_wp() {
    local compose_file=$(get_compose_file)
    local compose_cmd=$(get_compose_command "$compose_file")
    
    cd "$INSTALL_PATH/setup"
    $compose_cmd exec -T wpcli wp "${WP_ARGS[@]}"
}

# Main execution
main() {
    # Get script directory
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    
    # Change to script directory for relative paths
    cd "$SCRIPT_DIR"
    
    # Load environment first
    load_env
    
    # Parse arguments (may override env vars)
    parse_args "$@"
    
    # Detect container engine
    detect_container_engine
    
    # Execute action
    case $ACTION in
        up)
            action_up
            ;;
        down)
            action_down
            ;;
        install)
            action_install
            ;;
        clean)
            action_clean
            ;;
        status)
            action_status
            ;;
        logs)
            action_logs
            ;;
        shell)
            action_shell
            ;;
        wp)
            action_wp
            ;;
        *)
            log_error "Unknown action: $ACTION"
            show_usage
            exit 1
            ;;
    esac
}

# Run main function
main "$@"
