#!/bin/bash
# WebP Safe Migrator - One-Click Deployment
# The simplest possible deployment - just run and go!

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

show_help() {
    echo -e "${GREEN}🚀 WebP Safe Migrator - One-Click Deployment${NC}"
    echo ""
    echo "This script automatically:"
    echo -e "  ✅ Downloads and starts all containers"
    echo -e "  ✅ Installs WordPress with optimal settings"
    echo -e "  ✅ Activates the WebP Safe Migrator plugin"
    echo -e "  ✅ Creates sample content for testing"
    echo -e "  ✅ Opens WordPress in your browser"
    echo ""
    echo "Usage:"
    echo "  ./one-click-deploy.sh        # Deploy everything automatically"
    echo "  ./one-click-deploy.sh --help # Show this help"
    echo ""
    echo "Access URLs after deployment:"
    echo -e "  🌐 WordPress: ${GREEN}http://localhost:8080${NC}"
    echo -e "  🔧 Admin: ${GREEN}http://localhost:8080/wp-admin${NC}"
    echo -e "  🗄️ Database: ${GREEN}http://localhost:8081${NC} (phpMyAdmin)"
    echo ""
    echo "Default credentials:"
    echo -e "  👤 Username: ${YELLOW}admin${NC}"
    echo -e "  🔑 Password: ${YELLOW}admin123!${NC}"
}

# Parse arguments
if [[ "$1" == "--help" ]] || [[ "$1" == "-h" ]]; then
    show_help
    exit 0
fi

echo ""
echo -e "${GREEN}🚀 WebP Safe Migrator - One-Click Deployment${NC}"
echo -e "${GREEN}=================================================${NC}"
echo ""
echo -e "${CYAN}Starting automated deployment...${NC}"
echo -e "${YELLOW}This will take 2-3 minutes to complete.${NC}"
echo ""

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Check if deployment script exists
DEPLOY_SCRIPT="$SCRIPT_DIR/webp-migrator-deploy.sh"

if [[ -f "$DEPLOY_SCRIPT" ]]; then
    echo -e "${CYAN}🔄 Executing deployment script...${NC}"
    
    # Make sure it's executable
    chmod +x "$DEPLOY_SCRIPT"
    
    # Execute the main deployment script with clean start
    if "$DEPLOY_SCRIPT" --clean-start; then
        echo ""
        echo -e "${GREEN}🎉 SUCCESS! WebP Safe Migrator is ready to use!${NC}"
        echo ""
        echo -e "${CYAN}🎯 Next steps:${NC}"
        echo "1. WordPress should open in your browser automatically"
        echo "2. Go to Media → WebP Migrator to test the plugin"
        echo "3. Upload some images and try the conversion features"
    else
        echo ""
        echo -e "${RED}❌ Deployment failed. Please check the error messages above.${NC}"
        exit 1
    fi
else
    echo -e "${RED}❌ Deployment script not found: $DEPLOY_SCRIPT${NC}"
    echo -e "${YELLOW}Make sure you're running this from the setup directory.${NC}"
    exit 1
fi
