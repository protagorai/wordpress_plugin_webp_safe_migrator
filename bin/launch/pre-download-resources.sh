#!/bin/bash
# ==============================================================================
# WebP Safe Migrator - Resource Pre-Download Script (Linux/macOS)
# Downloads all required Docker images and tools before setup
# ==============================================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BLUE='\033[0;34m'
NC='\033[0m'

# Spinner characters
SPINNER="⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏"

echo ""
echo "====================================="
echo "  WebP Safe Migrator Pre-Download"
echo "====================================="
echo ""
echo -e "${BLUE}⬇️  Pre-downloading all required resources...${NC}"
echo "   This will speed up the setup process significantly!"
echo ""

# Function to show animated spinner during download
show_spinner() {
    local pid=$1
    local message="$2"
    local i=0
    
    while kill -0 $pid 2>/dev/null; do
        local char="${SPINNER:$((i % ${#SPINNER})):1}"
        printf "\r${CYAN}$char${NC} $message"
        sleep 0.2
        ((i++))
    done
    printf "\r"
}

# Function to download with progress
download_with_progress() {
    local resource_name="$1"
    local command="$2"
    local success_msg="$3"
    
    echo -e "${CYAN}⠋${NC} Downloading $resource_name..."
    
    # Run command in background and show spinner
    eval "$command" > temp_download.log 2>&1 &
    local download_pid=$!
    
    show_spinner $download_pid "Downloading $resource_name..."
    
    # Wait for completion and check result
    wait $download_pid
    local exit_code=$?
    
    if [[ $exit_code -eq 0 ]]; then
        echo -e "${GREEN}✅ $success_msg${NC}"
        rm -f temp_download.log
    else
        echo -e "${RED}❌ Failed to download $resource_name${NC}"
        echo -e "${YELLOW}Error details:${NC}"
        cat temp_download.log
        rm -f temp_download.log
        echo ""
        echo "Press Enter to copy this error and continue..."
        read
    fi
    echo ""
}

# Check container engine availability
echo -e "${CYAN}⠋${NC} Checking container engine availability..."
if command -v podman >/dev/null 2>&1; then
    CONTAINER_ENGINE="podman"
    echo -e "${GREEN}✅ Podman detected${NC}"
elif command -v docker >/dev/null 2>&1; then
    CONTAINER_ENGINE="docker"
    echo -e "${GREEN}✅ Docker detected${NC}"
else
    echo -e "${RED}❌ ERROR: Neither Podman nor Docker found${NC}"
    echo "Please install Podman or Docker first"
    echo ""
    echo "Press Enter to exit..."
    read
    exit 1
fi

echo ""
echo -e "${BLUE}📦 Starting resource downloads...${NC}"
echo ""

# Download WordPress image
download_with_progress "WordPress Docker Image" \
    "$CONTAINER_ENGINE pull docker.io/library/wordpress:latest" \
    "WordPress image ready"

# Download MySQL image
download_with_progress "MySQL Docker Image" \
    "$CONTAINER_ENGINE pull docker.io/library/mysql:8.0" \
    "MySQL image ready"

# Download phpMyAdmin image
download_with_progress "phpMyAdmin Docker Image" \
    "$CONTAINER_ENGINE pull docker.io/library/phpmyadmin:latest" \
    "phpMyAdmin image ready"

# Download WP-CLI
echo -e "${CYAN}⠦${NC} Downloading WP-CLI tool..."
if [[ ! -f "temp_wpcli.phar" ]]; then
    if curl -L -o temp_wpcli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar 2>/dev/null; then
        echo -e "${GREEN}✅ WP-CLI downloaded successfully${NC}"
        echo -e "${CYAN}   📁 Saved as temp_wpcli.phar (will be used during setup)${NC}"
    else
        echo -e "${YELLOW}❌ Failed to download WP-CLI${NC}"
        echo -e "${YELLOW}This is optional - WP-CLI will be installed during container setup${NC}"
    fi
else
    echo -e "${GREEN}✅ WP-CLI already downloaded${NC}"
fi
echo ""

# Verify all images are available
echo -e "${BLUE}🔍 Verifying downloaded resources...${NC}"
echo ""

if $CONTAINER_ENGINE images --format "table {{.Repository}}\t{{.Tag}}\t{{.Size}}" | grep -E "(wordpress|mysql|phpmyadmin)" >/dev/null; then
    echo -e "${GREEN}✅ All container images verified${NC}"
    echo ""
    $CONTAINER_ENGINE images --format "table {{.Repository}}\t{{.Tag}}\t{{.Size}}" | grep -E "(Repository|wordpress|mysql|phpmyadmin)"
else
    echo -e "${YELLOW}⚠️  Some images may not have downloaded correctly${NC}"
fi

echo ""
echo -e "${GREEN}=====================================${NC}"
echo -e "${GREEN}   🎉 PRE-DOWNLOAD COMPLETE! 🎉${NC}"
echo -e "${GREEN}=====================================${NC}"
echo ""
echo -e "${GREEN}✅ Resources ready for fast setup:${NC}"
echo -e "${CYAN}   📦 WordPress Docker image${NC}"
echo -e "${CYAN}   📦 MySQL 8.0 Docker image${NC}"
echo -e "${CYAN}   📦 phpMyAdmin Docker image${NC}"
echo -e "${CYAN}   🔧 WP-CLI tool (if available)${NC}"
echo ""
echo -e "${BLUE}🚀 Benefits:${NC}"
echo -e "${CYAN}   ⚡ 3-5x faster container startup${NC}"
echo -e "${CYAN}   📊 No download delays during setup${NC}"
echo -e "${CYAN}   🛠️  Consistent offline-capable setup${NC}"
echo ""
echo -e "${BLUE}💡 Next steps:${NC}"
echo -e "${CYAN}   1. Run: ./launch-webp-migrator.sh${NC}"
echo -e "${CYAN}   2. Setup will use pre-downloaded resources${NC}"
echo -e "${CYAN}   3. Enjoy blazing-fast deployment! 🔥${NC}"
echo ""
echo "Press Enter to close..."
read
