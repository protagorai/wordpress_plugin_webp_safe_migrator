#!/bin/bash
# ==============================================================================
# WebP Safe Migrator - Main Entry Point (Linux/macOS)
# Unified interface for all WebP migrator operations  
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
echo -e "${GREEN}   WebP Safe Migrator v1.0${NC}"
echo -e "${GREEN}=====================================${NC}"
echo ""

if [[ $# -eq 0 ]]; then
    show_help
    exit 0
fi

show_help() {
    echo -e "${BLUE}🚀 WebP Safe Migrator - WordPress Plugin Development Environment${NC}"
    echo ""
    echo -e "${CYAN}COMMANDS:${NC}"
    echo "  start       Start the development environment (equivalent to launch)"
    echo "  stop        Stop all containers (keep data)"
    echo "  restart     Stop and start the environment"
    echo "  clean       Complete cleanup (removes all data)"
    echo "  status      Show current container status"
    echo "  download    Pre-download resources for faster setup"
echo "  manage      WordPress management utilities"
echo "  fix         Fix upload permissions (if uploads fail)"
echo "  help        Show this help message"
    echo ""
    echo -e "${CYAN}EXAMPLES:${NC}"
    echo "  ./webp-migrator.sh start         # Start everything"
echo "  ./webp-migrator.sh stop          # Stop containers"
echo "  ./webp-migrator.sh clean         # Clean slate"
echo "  ./webp-migrator.sh download      # Pre-download for speed"
echo "  ./webp-migrator.sh manage        # WordPress management"
echo "  ./webp-migrator.sh fix           # Fix upload permissions"
    echo ""
    echo -e "${YELLOW}💡 TIP: Run './webp-migrator.sh download' first for fastest setup!${NC}"
    echo ""
    echo -e "${CYAN}📚 Documentation: docs/guides/QUICK_START.md${NC}"
}

case "$1" in
    start)
        echo -e "${BLUE}🚀 Starting WebP Safe Migrator environment...${NC}"
        ./webp-migrator-simple.sh "${@:2}"
        ;;
    stop)
        echo -e "${YELLOW}⏹️ Stopping WebP Safe Migrator environment...${NC}"
        (cd bin/manage && ./stop-webp-migrator.sh)
        ;;
    restart)
        echo -e "${CYAN}🔄 Restarting WebP Safe Migrator environment...${NC}"
        (cd bin/manage && ./stop-webp-migrator.sh)
        sleep 3
        (cd bin/launch && ./launch-webp-migrator.sh "${@:2}")
        ;;
    clean)
        echo -e "${RED}🧹 Cleaning WebP Safe Migrator environment...${NC}"
        (cd bin/manage && ./cleanup-webp-migrator.sh)
        ;;
    status)
        echo -e "${CYAN}📊 WebP Safe Migrator status...${NC}"
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
