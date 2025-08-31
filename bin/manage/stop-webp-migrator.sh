#!/bin/bash
# ==============================================================================
# WebP Safe Migrator - Linux/macOS Stop Script
# Stops all containers safely
# ==============================================================================

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo ""
echo "====================================="
echo "  WebP Safe Migrator Cleanup"
echo "====================================="
echo ""

echo "Stopping WebP Safe Migrator containers..."
echo ""

# Stop all containers gracefully
echo "Stopping WordPress container..."
if podman stop webp-migrator-wordpress 2>/dev/null; then
    echo -e "${GREEN}✓ WordPress stopped${NC}"
else
    echo -e "${YELLOW}! WordPress was not running${NC}"
fi

echo "Stopping phpMyAdmin container..."
if podman stop webp-migrator-phpmyadmin 2>/dev/null; then
    echo -e "${GREEN}✓ phpMyAdmin stopped${NC}"
else
    echo -e "${YELLOW}! phpMyAdmin was not running${NC}"
fi

echo "Stopping MySQL database..."
if podman stop webp-migrator-mysql 2>/dev/null; then
    echo -e "${GREEN}✓ MySQL stopped${NC}"
else
    echo -e "${YELLOW}! MySQL was not running${NC}"
fi

echo ""
echo "All WebP Safe Migrator containers have been stopped."
echo ""
echo "Note: Containers are stopped but not removed."
echo "      Your data is preserved."
echo "      Run ./launch-webp-migrator.sh to start again."
echo ""

# Show remaining containers
echo "Current container status:"
podman ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" | grep webp-migrator || echo "No WebP Migrator containers running."

echo ""
echo -e "${GREEN}Stop completed successfully!${NC}"
