#!/bin/bash
# WebP Safe Migrator - Universal Container Setup
# Automatically detects and uses Docker or Podman for cross-platform development

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
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

# Configuration
INSTALL_PATH="$HOME/webp-migrator-test"
CONTAINER_ENGINE=""
COMPOSE_COMMAND=""

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

# Detect available container engine
detect_container_engine() {
    log_info "Detecting available container engines..."
    
    # Check for Podman first (preferred due to licensing)
    if command -v podman >/dev/null 2>&1; then
        # Test if Podman is working
        if podman info >/dev/null 2>&1; then
            CONTAINER_ENGINE="podman"
            COMPOSE_COMMAND="podman-compose"
            
            # Check for podman-compose
            if ! command -v podman-compose >/dev/null 2>&1; then
                log_warning "podman-compose not found, using direct podman commands"
                COMPOSE_COMMAND="podman"
            fi
            
            log_success "Using Podman (recommended - Apache 2.0 license, no restrictions)"
            return 0
        fi
    fi
    
    # Check for Docker as fallback
    if command -v docker >/dev/null 2>&1; then
        if docker info >/dev/null 2>&1; then
            CONTAINER_ENGINE="docker"
            COMPOSE_COMMAND="docker-compose"
            
            log_warning "Using Docker (⚠️  Commercial licensing restrictions may apply)"
            log_info "Consider installing Podman for unrestricted use"
            return 0
        fi
    fi
    
    # No container engine available
    log_error "No container engine available. Please install either:"
    echo ""
    echo -e "${GREEN}Podman (Recommended):${NC}"
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
            echo "  WSL: sudo apt-get install podman"
            echo "  Or: Install Podman Desktop for Windows"
            ;;
    esac
    echo ""
    echo -e "${YELLOW}Docker (Alternative):${NC}"
    case $PLATFORM in
        "linux")
            echo "  Ubuntu/Debian: sudo apt-get install docker.io docker-compose"
            echo "  CentOS/RHEL: sudo yum install docker docker-compose"
            ;;
        "macos"|"wsl")
            echo "  Docker Desktop (requires license for commercial use)"
            ;;
    esac
    echo ""
    echo -e "${CYAN}Why Podman?${NC}"
    echo "• Fully open-source (Apache 2.0 license)"
    echo "• No commercial licensing restrictions"
    echo "• Rootless containers (better security)"
    echo "• No daemon required"
    echo "• Compatible with Docker commands"
    
    exit 1
}

# Show usage
show_usage() {
    echo -e "${GREEN}${BOLD}WebP Safe Migrator - Universal Container Setup${NC}"
    echo ""
    echo "This script automatically detects and uses the best available container engine"
    echo "(Podman preferred, Docker as fallback) for cross-platform development."
    echo ""
    echo "Usage: $0 ACTION [OPTIONS]"
    echo ""
    echo "Actions:"
    echo "  up           Start development environment"
    echo "  down         Stop environment"
    echo "  restart      Restart environment"
    echo "  logs         Show logs"
    echo "  status       Show status"
    echo "  shell        Open WordPress shell"
    echo "  wp           Execute WP-CLI commands"
    echo "  mysql        Open MySQL shell"
    echo "  install      Install WordPress + plugin"
    echo "  clean        Remove everything"
    echo "  backup       Create backup"
    echo "  restore      Restore from backup"
    echo ""
    echo "Options:"
    echo "  --path PATH      Installation path (default: ~/webp-migrator-test)"
    echo "  --force-docker   Use Docker even if Podman is available"
    echo "  --follow         Follow logs (for logs action)"
    echo "  --help           Show this help"
    echo ""
    echo "Examples:"
    echo "  $0 up                    # Start environment (auto-detects engine)"
    echo "  $0 install               # Install WordPress + plugin"
    echo "  $0 wp plugin list        # Use WP-CLI"
    echo "  $0 status                # Check everything"
}

# Parse arguments
ACTION="$1"
shift || true

FORCE_DOCKER=false
FOLLOW=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --path)
            INSTALL_PATH="$2"
            shift 2
            ;;
        --force-docker)
            FORCE_DOCKER=true
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
            # Pass remaining args to the action
            break
            ;;
    esac
done

if [[ -z "$ACTION" ]]; then
    show_usage
    exit 1
fi

# Detect platform and container engine
detect_platform

if [[ "$FORCE_DOCKER" == true ]]; then
    log_info "Forcing Docker usage..."
    CONTAINER_ENGINE="docker"
    COMPOSE_COMMAND="docker-compose"
    
    if ! command -v docker >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then
        log_error "Docker not available or not running"
        exit 1
    fi
else
    detect_container_engine
fi

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Execute the appropriate script based on detected engine
case $CONTAINER_ENGINE in
    "podman")
        log_info "Using Podman for container management"
        
        PODMAN_SCRIPT="$SCRIPT_DIR/podman-setup.sh"
        if [[ ! -f "$PODMAN_SCRIPT" ]]; then
            log_error "Podman setup script not found: $PODMAN_SCRIPT"
            exit 1
        fi
        
        chmod +x "$PODMAN_SCRIPT"
        
        # Convert arguments for podman script
        PODMAN_ARGS="$ACTION"
        if [[ "$FOLLOW" == true ]]; then
            PODMAN_ARGS="$PODMAN_ARGS --follow"
        fi
        if [[ "$INSTALL_PATH" != "$HOME/webp-migrator-test" ]]; then
            PODMAN_ARGS="$PODMAN_ARGS --path $INSTALL_PATH"
        fi
        
        exec "$PODMAN_SCRIPT" $PODMAN_ARGS "$@"
        ;;
        
    "docker")
        log_info "Using Docker for container management"
        log_warning "⚠️  Docker Desktop requires paid licenses for commercial use (>250 employees or >$10M revenue)"
        
        DOCKER_SCRIPT="$SCRIPT_DIR/docker-setup.sh"
        if [[ ! -f "$DOCKER_SCRIPT" ]]; then
            log_error "Docker setup script not found: $DOCKER_SCRIPT"
            exit 1
        fi
        
        chmod +x "$DOCKER_SCRIPT"
        
        # Convert arguments for docker script
        DOCKER_ARGS="$ACTION"
        if [[ "$FOLLOW" == true ]]; then
            DOCKER_ARGS="$DOCKER_ARGS --follow"
        fi
        
        exec "$DOCKER_SCRIPT" $DOCKER_ARGS "$@"
        ;;
        
    *)
        log_error "No container engine detected"
        exit 1
        ;;
esac
