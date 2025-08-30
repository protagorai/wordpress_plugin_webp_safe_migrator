#!/bin/bash
# WebP Safe Migrator - Universal Setup Script
# Automatically detects the best installation method for your system

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

# Detect system capabilities
detect_system() {
    log_info "Detecting system capabilities..."
    
    # Detect OS
    if [[ "$OSTYPE" == "linux-gnu"* ]]; then
        OS="linux"
        if command -v apt-get >/dev/null 2>&1; then
            DISTRO="ubuntu"
        elif command -v yum >/dev/null 2>&1; then
            DISTRO="centos"
        elif command -v pacman >/dev/null 2>&1; then
            DISTRO="arch"
        else
            DISTRO="unknown"
        fi
    elif [[ "$OSTYPE" == "darwin"* ]]; then
        OS="macos"
        DISTRO="macos"
    else
        OS="unknown"
        DISTRO="unknown"
    fi
    
    # Check for Docker
    DOCKER_AVAILABLE=false
    if command -v docker >/dev/null 2>&1 && command -v docker-compose >/dev/null 2>&1; then
        if docker info >/dev/null 2>&1; then
            DOCKER_AVAILABLE=true
        fi
    fi
    
    # Check for package managers
    PACKAGE_MANAGER_AVAILABLE=false
    case $DISTRO in
        ubuntu|centos|arch)
            PACKAGE_MANAGER_AVAILABLE=true
            ;;
        macos)
            if command -v brew >/dev/null 2>&1; then
                PACKAGE_MANAGER_AVAILABLE=true
            fi
            ;;
    esac
    
    # Check for admin privileges
    ADMIN_AVAILABLE=false
    if [[ $EUID -eq 0 ]] || sudo -n true 2>/dev/null; then
        ADMIN_AVAILABLE=true
    fi
    
    log_success "System detection complete"
    echo "  OS: $OS ($DISTRO)"
    echo "  Docker: $(if [[ "$DOCKER_AVAILABLE" == true ]]; then echo "Available"; else echo "Not available"; fi)"
    echo "  Package Manager: $(if [[ "$PACKAGE_MANAGER_AVAILABLE" == true ]]; then echo "Available"; else echo "Not available"; fi)"
    echo "  Admin Privileges: $(if [[ "$ADMIN_AVAILABLE" == true ]]; then echo "Available"; else echo "Not available"; fi)"
}

# Show installation options
show_options() {
    echo ""
    echo -e "${BOLD}=== Installation Options ===${NC}"
    echo ""
    
    if [[ "$DOCKER_AVAILABLE" == true ]]; then
        echo -e "${GREEN}1. Docker Setup (Recommended)${NC}"
        echo "   ✅ No system dependencies required"
        echo "   ✅ Isolated environment"
        echo "   ✅ Easy cleanup"
        echo "   ✅ Cross-platform compatibility"
        echo ""
    fi
    
    if [[ "$PACKAGE_MANAGER_AVAILABLE" == true ]] && [[ "$ADMIN_AVAILABLE" == true ]]; then
        echo -e "${CYAN}2. Native Installation${NC}"
        echo "   ✅ Better performance"
        echo "   ✅ Direct system integration"
        echo "   ⚠️  Requires system packages"
        echo "   ⚠️  May conflict with existing services"
        echo ""
    fi
    
    echo -e "${YELLOW}3. Manual Setup${NC}"
    echo "   ⚠️  Requires existing WordPress installation"
    echo "   ✅ Use with existing development environment"
    echo ""
}

# Get user choice
get_user_choice() {
    local choices=()
    local choice_descriptions=()
    
    if [[ "$DOCKER_AVAILABLE" == true ]]; then
        choices+=("docker")
        choice_descriptions+=("Docker Setup (Recommended)")
    fi
    
    if [[ "$PACKAGE_MANAGER_AVAILABLE" == true ]] && [[ "$ADMIN_AVAILABLE" == true ]]; then
        choices+=("native")
        choice_descriptions+=("Native Installation")
    fi
    
    choices+=("manual")
    choice_descriptions+=("Manual Setup (existing WordPress)")
    
    if [[ ${#choices[@]} -eq 0 ]]; then
        log_error "No installation options available on this system"
        echo "Please install Docker or ensure you have admin privileges"
        exit 1
    fi
    
    echo "Please select an installation method:"
    for i in "${!choices[@]}"; do
        echo "  $((i+1)). ${choice_descriptions[$i]}"
    done
    echo ""
    
    # Auto-select Docker if it's the only good option
    if [[ ${#choices[@]} -eq 1 ]] && [[ "${choices[0]}" == "docker" ]]; then
        log_info "Auto-selecting Docker setup (only available option)"
        SELECTED_METHOD="docker"
        return 0
    fi
    
    # Auto-select Docker if available and no admin privileges
    if [[ "$DOCKER_AVAILABLE" == true ]] && [[ "$ADMIN_AVAILABLE" != true ]]; then
        log_info "Auto-selecting Docker setup (no admin privileges for native install)"
        SELECTED_METHOD="docker"
        return 0
    fi
    
    echo -n "Enter your choice (1-${#choices[@]}): "
    read -r choice
    
    if ! [[ "$choice" =~ ^[0-9]+$ ]] || [[ $choice -lt 1 ]] || [[ $choice -gt ${#choices[@]} ]]; then
        log_error "Invalid choice"
        exit 1
    fi
    
    SELECTED_METHOD="${choices[$((choice-1))]}"
}

# Execute installation
execute_installation() {
    local script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    
    case $SELECTED_METHOD in
        docker)
            log_info "🐳 Starting Docker-based installation..."
            
            # Make sure docker-setup.sh is executable
            chmod +x "$script_dir/docker-setup.sh"
            
            # Start Docker environment
            "$script_dir/docker-setup.sh" up
            
            # Install WordPress and plugin
            "$script_dir/docker-setup.sh" install
            
            log_success "Docker-based installation completed!"
            ;;
            
        native)
            log_info "🔧 Starting native installation..."
            
            # Determine which script to use
            if [[ -f "$script_dir/install-wordpress-automated.sh" ]]; then
                chmod +x "$script_dir/install-wordpress-automated.sh"
                "$script_dir/install-wordpress-automated.sh"
            else
                chmod +x "$script_dir/install-wordpress.sh"
                "$script_dir/install-wordpress.sh" --start-services
                
                # Install plugin separately
                chmod +x "$script_dir/plugin-manager.sh"
                "$script_dir/plugin-manager.sh" install --use-wpcli
            fi
            
            log_success "Native installation completed!"
            ;;
            
        manual)
            log_info "📋 Manual setup instructions..."
            
            echo ""
            echo "For manual setup with existing WordPress:"
            echo ""
            echo "1. Ensure WordPress is running and accessible"
            echo "2. Install the plugin:"
            echo "   ./setup/plugin-manager.sh install --wordpress-path /path/to/wordpress"
            echo ""
            echo "3. Or copy files manually:"
            echo "   cp -r src/* /path/to/wordpress/wp-content/plugins/webp-safe-migrator/"
            echo ""
            echo "4. Activate in WordPress admin: Plugins → WebP Safe Migrator"
            echo ""
            return 0
            ;;
    esac
}

# Main execution
main() {
    echo -e "${GREEN}${BOLD}🚀 WebP Safe Migrator - Universal Setup${NC}"
    echo "This script will automatically set up a WordPress development environment"
    echo "for testing the WebP Safe Migrator plugin."
    echo ""
    
    detect_system
    show_options
    get_user_choice
    
    echo ""
    log_info "Selected installation method: $SELECTED_METHOD"
    echo ""
    
    execute_installation
    
    echo ""
    echo -e "${GREEN}${BOLD}🎉 Setup Complete!${NC}"
    echo ""
    
    case $SELECTED_METHOD in
        docker)
            echo -e "${CYAN}📍 Access Your Environment:${NC}"
            echo "   🌐 WordPress: http://localhost:8080"
            echo "   🔧 Admin: http://localhost:8080/wp-admin"
            echo "   🗄️  phpMyAdmin: http://localhost:8081"
            echo ""
            echo -e "${CYAN}🔑 Credentials:${NC}"
            echo "   👤 Username: admin"
            echo "   🔑 Password: admin123"
            echo ""
            echo -e "${CYAN}🛠️  Development:${NC}"
            echo "   📁 Plugin files: Edit in src/ directory (auto-synced)"
            echo "   🔧 WP-CLI: ./setup/docker-setup.sh wp [command]"
            echo "   📊 Logs: ./setup/docker-setup.sh logs --follow"
            echo "   🛑 Stop: ./setup/docker-setup.sh down"
            ;;
        native)
            echo -e "${CYAN}📍 Access Your Environment:${NC}"
            echo "   🌐 WordPress: http://localhost:8080"
            echo "   🔧 Admin: http://localhost:8080/wp-admin"
            echo ""
            echo -e "${CYAN}🛠️  Management:${NC}"
            echo "   ▶️  Start: ~/webp-migrator-test/scripts/start-services.sh"
            echo "   ⏹️  Stop: ~/webp-migrator-test/scripts/stop-services.sh"
            echo "   🔌 Plugin: ./setup/plugin-manager.sh [action]"
            ;;
    esac
    
    echo ""
    echo -e "${YELLOW}📚 Documentation: ./documentation/INDEX.md${NC}"
    echo -e "${YELLOW}🧪 Run Tests: phpunit tests/${NC}"
    echo ""
}

# Show help if requested
if [[ "$1" == "--help" ]] || [[ "$1" == "-h" ]]; then
    echo "WebP Safe Migrator - Universal Setup Script"
    echo ""
    echo "This script automatically detects your system and chooses the best"
    echo "installation method for setting up a WordPress development environment."
    echo ""
    echo "Supported installation methods:"
    echo "  • Docker (recommended) - Works on any system with Docker"
    echo "  • Native - Uses system package managers (Linux/macOS)"
    echo "  • Manual - For existing WordPress installations"
    echo ""
    echo "Usage: $0"
    echo ""
    echo "The script will:"
    echo "1. Detect your system capabilities"
    echo "2. Show available installation options"
    echo "3. Guide you through the setup process"
    echo "4. Install WordPress + WebP Safe Migrator plugin"
    echo ""
    echo "No arguments needed - the script is fully interactive!"
    exit 0
fi

# Run main function
main
