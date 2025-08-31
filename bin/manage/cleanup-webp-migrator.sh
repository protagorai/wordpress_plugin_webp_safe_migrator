#!/bin/bash
# ==============================================================================
# WebP Safe Migrator - Linux/macOS Complete Cleanup Script
# WARNING: This removes ALL containers and data!
# ==============================================================================

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo ""
echo "====================================="
echo "  WebP Safe Migrator COMPLETE CLEANUP"
echo "====================================="
echo ""
echo -e "${RED}WARNING: This will remove ALL containers and data!${NC}"
echo "This action cannot be undone."
echo ""
read -p "Are you sure you want to continue? (y/N): " confirm
if [[ "$confirm" != "y" ]] && [[ "$confirm" != "Y" ]]; then
    echo "Cleanup cancelled."
    exit 0
fi

echo ""
echo "Performing complete cleanup..."
echo ""

# Stop all containers
echo "Stopping all containers..."
podman stop webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin 2>/dev/null || true

# Remove all containers
echo "Removing all containers..."
if podman rm -f webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin 2>/dev/null; then
    echo -e "${GREEN}✓ Containers removed${NC}"
else
    echo -e "${YELLOW}! Some containers may not have existed${NC}"
fi

# Remove volumes
echo "Removing volumes..."
if podman volume rm wordpress_data 2>/dev/null; then
    echo -e "${GREEN}✓ WordPress data volume removed${NC}"
else
    echo -e "${YELLOW}! WordPress data volume may not have existed${NC}"
fi

# Remove network
echo "Removing network..."
if podman network rm webp-migrator-net 2>/dev/null; then
    echo -e "${GREEN}✓ Network removed${NC}"
else
    echo -e "${YELLOW}! Network may not have existed${NC}"
fi

echo ""
echo "====================================="
echo "  COMPLETE CLEANUP FINISHED"
echo "====================================="
echo ""
echo "All WebP Safe Migrator containers, volumes, and networks have been removed."
echo "To start fresh, run: ./launch-webp-migrator.sh"
echo ""
echo -e "${GREEN}Cleanup completed successfully!${NC}"
