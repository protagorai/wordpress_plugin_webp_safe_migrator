#!/bin/bash
# ==============================================================================
# Multi-Plugin WordPress Development Environment - Main Deployment Script (Linux/macOS)
# Configuration-driven deployment supporting multiple plugins
# ==============================================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BLUE='\033[0;34m'
NC='\033[0m'

echo ""
echo -e "${GREEN}=====================================${NC}"
echo -e "${GREEN}   Multi-Plugin WordPress Dev Environment v2.0${NC}"
echo -e "${GREEN}=====================================${NC}"
echo ""

if [[ $# -eq 0 ]]; then
    show_help
    exit 0
fi

show_help() {
    echo -e "${BLUE}🚀 Multi-Plugin WordPress Development Environment${NC}"
    echo ""
    echo -e "${CYAN}COMMANDS:${NC}"
    echo "  start       Start the development environment (equivalent to launch)"
    echo "  stop        Stop all containers (keep data)"
    echo "  restart     Stop and start the environment"
    echo "  clean       Complete cleanup (removes all data)"
    echo "  status      Show current container status"
    echo "  download    Pre-download resources for faster setup"
    echo "  manage      WordPress management utilities"
    echo "  plugins     Multi-plugin management commands"
    echo "  fix         Fix upload permissions (if uploads fail)"
    echo "  help        Show this help message"
    echo ""
    echo -e "${CYAN}EXAMPLES:${NC}"
    echo "  ./deploy.sh start                         # Start multi-plugin environment"
    echo "  ./deploy.sh stop                          # Stop containers"
    echo "  ./deploy.sh clean                         # Clean slate"
    echo "  ./deploy.sh plugins list                  # List available plugins"
    echo "  ./deploy.sh plugins activate              # Show plugin status"
    echo "  ./deploy.sh plugins activate PLUGIN       # Activate specific plugin"
    echo "  ./deploy.sh plugins status                # Check WordPress plugin status"
    echo "  ./deploy.sh fix                           # Fix upload permissions"
    echo ""
    echo -e "${CYAN}MULTI-PLUGIN MANAGEMENT:${NC}"
    echo "  ./setup/multi-plugin-manager.sh list                    # List available plugins"
    echo "  ./setup/multi-plugin-manager.sh install-all --profile development  # Deploy development plugins"
    echo "  ./setup/multi-plugin-manager.sh status                 # Show plugin status"
    echo ""
    echo -e "${YELLOW}💡 TIP: Run './deploy.sh download' first for fastest setup!${NC}"
    echo ""
    echo -e "${CYAN}📚 Documentation: docs/INDEX.md${NC}"
}

case "$1" in
    start)
        echo -e "${BLUE}🚀 Starting Multi-Plugin WordPress environment...${NC}"
        ./webp-migrator-simple.sh "${@:2}"
        ;;
    stop)
        echo -e "${YELLOW}⏹️ Stopping Multi-Plugin environment...${NC}"
        (cd bin/manage && ./stop-webp-migrator.sh)
        ;;
    restart)
        echo -e "${CYAN}🔄 Restarting Multi-Plugin environment...${NC}"
        (cd bin/manage && ./stop-webp-migrator.sh)
        sleep 3
        (cd bin/launch && ./launch-webp-migrator.sh "${@:2}")
        ;;
    clean)
        echo -e "${RED}🧹 Cleaning Multi-Plugin environment...${NC}"
        (cd bin/manage && ./cleanup-webp-migrator.sh)
        ;;
    status)
        echo -e "${CYAN}📊 Multi-Plugin environment status...${NC}"
        (cd bin/manage && ./status-webp-migrator.sh)
        ;;
    download)
        echo -e "${BLUE}⬇️ Pre-downloading resources...${NC}"
        (cd bin/launch && ./pre-download-resources.sh)
        ;;
    manage)
        echo -e "${CYAN}🔧 WordPress management...${NC}"
        if [[ $# -eq 1 ]]; then
            (cd bin/manage && ./manage-wp.sh)
        else
            (cd bin/manage && ./manage-wp.sh "${@:2}")
        fi
        ;;
    plugins)
        echo -e "${CYAN}🔌 Multi-plugin management...${NC}"
        if [[ $# -eq 1 ]]; then
            # List available plugins
            if [[ -f "setup/clean-plugin-list.ps1" ]]; then
                powershell -ExecutionPolicy Bypass -File "setup/clean-plugin-list.ps1" -Action "list" 2>/dev/null || {
                    echo -e "${YELLOW}PowerShell not available, using bash fallback...${NC}"
                    if [[ -d "src" ]]; then
                        echo "Available plugins in src/:"
                        for plugin_dir in src/*/; do
                            if [[ -d "$plugin_dir" ]]; then
                                local plugin_name=$(basename "$plugin_dir")
                                echo "  - $plugin_name"
                            fi
                        done
                    fi
                }
            fi
        elif [[ "$2" == "deploy" ]]; then
            # Deploy plugins to container
            echo -e "${CYAN}Deploying plugins to running container...${NC}"
            if [[ -f "setup/deploy-plugins-to-container.sh" ]]; then
                chmod +x setup/deploy-plugins-to-container.sh
                ./setup/deploy-plugins-to-container.sh webp-migrator-wordpress --profile development
            else
                echo -e "${RED}Configuration deployment script not found${NC}"
            fi
        elif [[ "$2" == "activate" ]]; then
            if [[ -z "$3" ]]; then
                echo -e "${CYAN}Showing current plugin status...${NC}"
                if command -v powershell >/dev/null 2>&1; then
                    powershell -ExecutionPolicy Bypass -File "setup/activate-plugin-manually.ps1"
                else
                    echo -e "${CYAN}Current plugin status in WordPress:${NC}"
                    podman exec webp-migrator-wordpress wp plugin list --allow-root 2>/dev/null || {
                        echo -e "${RED}WordPress container not running or wp-cli not available${NC}"
                    }
                fi
            else
                echo -e "${CYAN}Activating plugin: $3${NC}"
                if podman exec webp-migrator-wordpress wp plugin activate "$3" --allow-root 2>/dev/null; then
                    echo -e "${GREEN}✓ Plugin '$3' activated successfully!${NC}"
                else
                    echo -e "${RED}✗ Plugin activation failed${NC}"
                fi
            fi
        elif [[ "$2" == "status" ]]; then
            echo -e "${CYAN}Checking plugin status in WordPress...${NC}"
            podman exec webp-migrator-wordpress wp plugin list --allow-root 2>/dev/null
        else
            echo -e "${CYAN}Available plugin commands:${NC}"
            echo "  list       - List available plugins"
            echo "  deploy     - Deploy plugins to running container"
            echo "  activate   - Show plugin status or activate specific plugin"
            echo "  status     - Show WordPress plugin status"
            echo ""
            echo -e "${CYAN}Examples:${NC}"
            echo "  ./deploy.sh plugins list"
            echo "  ./deploy.sh plugins activate"
            echo "  ./deploy.sh plugins activate example-second-plugin"
            echo "  ./deploy.sh plugins status"
        fi
        ;;
    fix)
        echo -e "${YELLOW}🛠️ Fixing upload permissions...${NC}"
        (cd bin/manage && ./fix-uploads-ownership.sh)
        ;;
    help|--help|-h)
        show_help
        ;;
    *)
        echo -e "${RED}❌ Unknown command: $1${NC}"
        echo ""
        show_help
        exit 1
        ;;
esac
