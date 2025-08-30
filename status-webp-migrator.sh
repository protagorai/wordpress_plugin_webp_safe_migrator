#!/bin/bash
# ==============================================================================
# WebP Safe Migrator - Linux/macOS Status Checker
# Checks the status of all containers and services
# ==============================================================================

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo ""
echo "====================================="
echo "  WebP Safe Migrator Status"
echo "====================================="
echo ""

# Check if Podman is available
if ! command -v podman >/dev/null 2>&1; then
    echo -e "${RED}❌ Podman not found${NC}"
    echo "   Install from: https://podman.io/getting-started/installation"
    exit 1
else
    echo -e "${GREEN}✓ Podman is available${NC}"
fi

echo ""

# Check container status
echo "Container Status:"
echo "-----------------"
if podman ps -a --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" | grep webp-migrator >/dev/null; then
    podman ps -a --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" | grep webp-migrator
    
    echo ""
    echo "Running Containers:"
    echo "------------------"
    if podman ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" | grep webp-migrator >/dev/null; then
        podman ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" | grep webp-migrator
    else
        echo "No containers currently running."
        echo "Run ./launch-webp-migrator.sh to start the environment."
    fi
else
    echo "No WebP Safe Migrator containers found."
    echo "Run ./launch-webp-migrator.sh to start the environment."
fi

echo ""

# Check network
echo "Network Status:"
echo "---------------"
if podman network ls | grep webp-migrator-net >/dev/null; then
    echo -e "${GREEN}✓ WebP Migrator network exists${NC}"
else
    echo -e "${RED}❌ WebP Migrator network not found${NC}"
fi

echo ""

# Check volumes
echo "Volume Status:"
echo "--------------"
if podman volume ls | grep wordpress_data >/dev/null; then
    echo -e "${GREEN}✓ WordPress data volume exists${NC}"
else
    echo -e "${YELLOW}! WordPress data volume not found (first run or cleaned up)${NC}"
fi

echo ""

# Check WordPress Plugin Status
echo "WordPress Plugin Status:"
echo "------------------------"
echo "Checking active plugins..."
if podman exec webp-migrator-wpcli wp plugin list --status=active --format=table --allow-root 2>/dev/null; then
    echo ""
    echo "WebP Safe Migrator status:"
    if podman exec webp-migrator-wpcli wp plugin status webp-safe-migrator --allow-root 2>/dev/null; then
        echo -e "${GREEN}✓ WebP Safe Migrator is active and working${NC}"
    else
        echo -e "${RED}❌ WebP Safe Migrator not found or inactive${NC}"
    fi
else
    echo -e "${YELLOW}! WP-CLI not available or WordPress not ready${NC}"
fi

echo ""

# Check service accessibility
echo "Service Accessibility:"
echo "----------------------"

# Check WordPress
echo "Checking WordPress (http://localhost:8080)..."
if wordpress_status=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080" 2>/dev/null); then
    if [[ "$wordpress_status" == "200" ]]; then
        echo -e "${GREEN}✓ WordPress is accessible and running${NC}"
    elif [[ "$wordpress_status" =~ ^3 ]]; then
        echo -e "${GREEN}✓ WordPress is accessible (redirecting)${NC}"
    else
        echo -e "${YELLOW}! WordPress returned status: $wordpress_status${NC}"
    fi
else
    echo -e "${RED}❌ WordPress is not accessible${NC}"
fi

# Check phpMyAdmin
echo "Checking phpMyAdmin (http://localhost:8081)..."
if phpmyadmin_status=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:8081" 2>/dev/null); then
    if [[ "$phpmyadmin_status" == "200" ]]; then
        echo -e "${GREEN}✓ phpMyAdmin is accessible and running${NC}"
    elif [[ "$phpmyadmin_status" =~ ^3 ]]; then
        echo -e "${GREEN}✓ phpMyAdmin is accessible (redirecting)${NC}"
    else
        echo -e "${YELLOW}! phpMyAdmin returned status: $phpmyadmin_status${NC}"
    fi
else
    echo -e "${RED}❌ phpMyAdmin is not accessible${NC}"
fi

# Check MySQL
echo "Checking MySQL database connection..."
if podman exec webp-migrator-mysql mysqladmin ping -u root -proot123 >/dev/null 2>&1; then
    echo -e "${GREEN}✓ MySQL database is accessible${NC}"
else
    echo -e "${RED}❌ MySQL database is not accessible${NC}"
fi

echo ""

# Show quick access information
echo "Quick Access URLs:"
echo "-----------------"
echo "WordPress Site: http://localhost:8080"
echo "WordPress Admin: http://localhost:8080/wp-admin"
echo "phpMyAdmin: http://localhost:8081"
echo ""
echo "Default Credentials:"
echo "WordPress: admin / admin123!"
echo "Database: wordpress / wordpress123"
echo "DB Root: root / root123"
echo ""

# Show available actions
echo "Available Actions:"
echo "-----------------"
echo "./launch-webp-migrator.sh   - Start/restart the environment"
echo "./stop-webp-migrator.sh     - Stop containers (keep data)"
echo "./cleanup-webp-migrator.sh  - Complete cleanup (removes all data)"
echo "./status-webp-migrator.sh   - Show this status (current script)"
echo "./manage-wp.sh              - WordPress management commands"
echo ""

echo -e "${GREEN}Status check completed!${NC}"
