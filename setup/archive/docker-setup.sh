#!/bin/bash
# WebP Safe Migrator - Docker Development Environment
# Cross-platform WordPress development using Docker containers

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

# Detect platform
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
    
    if ! command -v docker >/dev/null 2>&1; then
        log_error "Docker not found. Please install Docker first:"
        echo ""
        case $PLATFORM in
            "linux")
                echo "  Ubuntu/Debian: sudo apt-get install docker.io docker-compose"
                echo "  CentOS/RHEL: sudo yum install docker docker-compose"
                echo "  Arch: sudo pacman -S docker docker-compose"
                ;;
            "macos")
                echo "  Docker Desktop: https://docs.docker.com/desktop/mac/install/"
                echo "  Homebrew: brew install --cask docker"
                ;;
            "wsl")
                echo "  Docker Desktop for Windows: https://docs.docker.com/desktop/windows/install/"
                echo "  Or use WSL: sudo apt-get install docker.io docker-compose"
                ;;
        esac
        echo ""
        echo "⚠️  Note: Docker Desktop requires paid licenses for commercial use."
        echo "   Consider using Podman instead: ./setup/podman-setup.sh"
        exit 1
    fi
    
    if ! command -v docker-compose >/dev/null 2>&1; then
        log_error "Docker Compose not found. Please install Docker Compose first:"
        echo "  https://docs.docker.com/compose/install/"
        exit 1
    fi
    
    # Check if Docker daemon is running
    if ! docker info >/dev/null 2>&1; then
        log_error "Docker daemon is not running. Please start Docker first."
        exit 1
    fi
    
    log_success "All dependencies available"
}

# Show usage
show_usage() {
    echo "WebP Safe Migrator - Docker Development Environment"
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
    echo "  --help       Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 up                    # Start environment"
    echo "  $0 wp plugin list        # List WordPress plugins"
    echo "  $0 wp webp-migrator run  # Run plugin via CLI"
    echo "  $0 logs --follow         # Watch logs"
    echo "  $0 shell                 # Open container shell"
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

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Ensure docker-compose.yml exists
if [[ ! -f "docker-compose.yml" ]]; then
    log_error "docker-compose.yml not found in $SCRIPT_DIR"
    exit 1
fi

# Main execution
case $ACTION in
    up)
        detect_platform
        check_dependencies
        log_info "Starting WebP Migrator development environment..."
        
        # Create uploads directory if it doesn't exist
        mkdir -p uploads
        
        if [[ "$DETACH" == true ]]; then
            docker-compose up -d
        else
            docker-compose up
        fi
        
        if [[ "$DETACH" == true ]]; then
            log_info "Waiting for services to be ready..."
            sleep 10
            
            # Check if WordPress is accessible
            for i in {1..30}; do
                if curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080" | grep -q "200\|30[0-9]"; then
                    break
                fi
                if [[ $i -eq 30 ]]; then
                    log_warning "WordPress may not be fully ready yet"
                fi
                sleep 2
            done
            
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
        fi
        ;;
        
    down)
        log_info "Stopping WebP Migrator development environment..."
        docker-compose down
        log_success "Environment stopped"
        ;;
        
    restart)
        log_info "Restarting WebP Migrator development environment..."
        docker-compose restart
        log_success "Environment restarted"
        ;;
        
    logs)
        if [[ "$FOLLOW" == true ]]; then
            docker-compose logs -f
        else
            docker-compose logs
        fi
        ;;
        
    status)
        echo -e "${CYAN}=== Container Status ===${NC}"
        docker-compose ps
        echo ""
        echo -e "${CYAN}=== Service Health ===${NC}"
        
        # Check WordPress
        if curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080" | grep -q "200\|30[0-9]"; then
            echo -e "WordPress: ${GREEN}✓ Running${NC} (http://localhost:8080)"
        else
            echo -e "WordPress: ${RED}✗ Not accessible${NC}"
        fi
        
        # Check phpMyAdmin
        if curl -s -o /dev/null -w "%{http_code}" "http://localhost:8081" | grep -q "200\|30[0-9]"; then
            echo -e "phpMyAdmin: ${GREEN}✓ Running${NC} (http://localhost:8081)"
        else
            echo -e "phpMyAdmin: ${RED}✗ Not accessible${NC}"
        fi
        
        # Check database
        if docker-compose exec -T db mysql -u wordpress -pwordpress123 -e "SELECT 1;" wordpress_webp_test >/dev/null 2>&1; then
            echo -e "Database: ${GREEN}✓ Connected${NC}"
        else
            echo -e "Database: ${RED}✗ Connection failed${NC}"
        fi
        ;;
        
    shell)
        log_info "Opening shell in WordPress container..."
        docker-compose exec wordpress bash
        ;;
        
    wp)
        log_info "Executing WP-CLI command: $*"
        docker-compose exec -T wpcli wp "$@"
        ;;
        
    mysql)
        log_info "Opening MySQL shell..."
        docker-compose exec db mysql -u root -proot123 wordpress_webp_test
        ;;
        
    install)
        log_info "Installing WordPress and WebP Safe Migrator plugin..."
        
        # First ensure containers are running
        docker-compose up -d
        
        # Wait for database
        log_info "Waiting for database to be ready..."
        for i in {1..30}; do
            if docker-compose exec -T db mysql -u wordpress -pwordpress123 -e "SELECT 1;" wordpress_webp_test >/dev/null 2>&1; then
                break
            fi
            if [[ $i -eq 30 ]]; then
                log_error "Database not ready after 60 seconds"
                exit 1
            fi
            sleep 2
        done
        
        # Install WordPress
        log_info "Installing WordPress core..."
        docker-compose exec -T wpcli wp core install \
            --url="http://localhost:8080" \
            --title="WebP Migrator Test Site" \
            --admin_user="admin" \
            --admin_password="admin123" \
            --admin_email="admin@webp-test.local" \
            --skip-email
        
        # Activate plugin
        log_info "Activating WebP Safe Migrator plugin..."
        docker-compose exec -T wpcli wp plugin activate webp-safe-migrator
        
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
            docker-compose down -v --remove-orphans
            docker system prune -f
            log_success "Environment cleaned"
        else
            log_info "Clean operation cancelled"
        fi
        ;;
        
    backup)
        log_info "Creating backup of WordPress data..."
        BACKUP_DIR="./backups/docker-$(date +%Y%m%d-%H%M%S)"
        mkdir -p "$BACKUP_DIR"
        
        # Backup database
        log_info "Backing up database..."
        docker-compose exec -T db mysqldump -u root -proot123 wordpress_webp_test > "$BACKUP_DIR/database.sql"
        
        # Backup WordPress files (plugins, themes, uploads)
        log_info "Backing up WordPress files..."
        docker-compose exec -T wordpress tar -czf /tmp/wordpress-backup.tar.gz -C /var/www/html wp-content
        docker cp webp-migrator-wordpress:/tmp/wordpress-backup.tar.gz "$BACKUP_DIR/"
        
        log_success "Backup created at: $BACKUP_DIR"
        ;;
        
    restore)
        log_info "Restoring from backup..."
        
        if [[ ! -d "./backups" ]]; then
            log_error "No backups directory found"
            exit 1
        fi
        
        # List available backups
        BACKUPS=($(find ./backups -type d -name "docker-*" | sort -r))
        
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
            docker-compose exec -T db mysql -u root -proot123 wordpress_webp_test < "$SELECTED_BACKUP/database.sql"
        fi
        
        # Restore WordPress files
        if [[ -f "$SELECTED_BACKUP/wordpress-backup.tar.gz" ]]; then
            log_info "Restoring WordPress files..."
            docker cp "$SELECTED_BACKUP/wordpress-backup.tar.gz" webp-migrator-wordpress:/tmp/
            docker-compose exec -T wordpress tar -xzf /tmp/wordpress-backup.tar.gz -C /var/www/html
        fi
        
        log_success "Restore completed"
        ;;
        
    *)
        log_error "Unknown action: $ACTION"
        show_usage
        exit 1
        ;;
esac
