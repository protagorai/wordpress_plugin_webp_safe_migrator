#!/bin/bash
# ==============================================================================
# WebP Safe Migrator - WordPress Management Utility (Linux/macOS)
# Provides common WordPress CLI commands for development
# ==============================================================================

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo ""
echo "====================================="
echo "  WordPress Management Utility"
echo "====================================="
echo ""

# Check if WordPress container is running
if ! podman ps --format "{{.Names}}" | grep -q webp-migrator-wordpress; then
    echo -e "${RED}ERROR: WP-CLI container not running${NC}"
    echo "Run ./launch-webp-migrator.sh first"
    exit 1
fi

ACTION=${1:-help}

case $ACTION in
    plugins)
        echo "Listing WordPress plugins..."
        podman exec webp-migrator-wordpress wp plugin list --format=table --allow-root
        ;;
        
    plugin-status)
        PLUGIN_NAME=${2:-webp-safe-migrator}
        echo "Checking $PLUGIN_NAME plugin status..."
        podman exec webp-migrator-wordpress wp plugin status "$PLUGIN_NAME" --allow-root
        ;;
        
    activate)
        PLUGIN_NAME=${2:-webp-safe-migrator}
        echo "Activating $PLUGIN_NAME plugin..."
        if podman exec webp-migrator-wordpress wp plugin activate "$PLUGIN_NAME" --allow-root; then
            echo -e "${GREEN}✓ Plugin activated successfully${NC}"
        else
            echo -e "${RED}❌ Plugin activation failed${NC}"
        fi
        ;;
        
    deactivate)
        PLUGIN_NAME=${2:-webp-safe-migrator}
        echo "Deactivating $PLUGIN_NAME plugin..."
        if podman exec webp-migrator-wordpress wp plugin deactivate "$PLUGIN_NAME" --allow-root; then
            echo -e "${GREEN}✓ Plugin deactivated successfully${NC}"
        else
            echo -e "${RED}❌ Plugin deactivation failed${NC}"
        fi
        ;;
        
    wp-info)
        echo "WordPress Installation Info:"
        echo "----------------------------"
        podman exec webp-migrator-wordpress wp core version --allow-root
        podman exec webp-migrator-wordpress wp core check-update --allow-root
        echo ""
        echo "WordPress Configuration:"
        podman exec webp-migrator-wordpress wp config list --allow-root
        ;;
        
    db-check)
        echo "Database Connection Test:"
        echo "------------------------"
        if podman exec webp-migrator-wordpress wp db check --allow-root; then
            echo -e "${GREEN}✓ Database connection OK${NC}"
        else
            echo -e "${RED}❌ Database connection failed${NC}"
        fi
        echo ""
        echo "Database Size:"
        podman exec webp-migrator-wordpress wp db size --allow-root
        ;;
        
    cache-flush)
        echo "Flushing WordPress caches..."
        podman exec webp-migrator-wordpress wp cache flush --allow-root
        podman exec webp-migrator-wordpress wp rewrite flush --allow-root
        echo -e "${GREEN}✓ WordPress caches flushed${NC}"
        ;;
        
    shell)
        echo "Opening WP-CLI shell..."
        podman exec -it webp-migrator-wordpress bash
        ;;
        
    help|*)
        echo "WordPress Management Utility"
        echo ""
        echo "Usage: ./manage-wp.sh [ACTION] [PLUGIN-NAME]"
        echo ""
        echo "Actions:"
        echo "  plugins           - List all plugins"
        echo "  plugin-status     - Check plugin status (WebP Migrator or specified plugin)"
        echo "  activate          - Activate plugin (WebP Migrator or specified plugin)"
        echo "  deactivate        - Deactivate plugin (WebP Migrator or specified plugin)"
        echo "  wp-info           - WordPress version and configuration info"
        echo "  db-check          - Database connectivity and size check"
        echo "  cache-flush       - Clear WordPress caches"
        echo "  shell             - Open interactive WP-CLI shell"
        echo "  help              - Show this help message"
        echo ""
        echo "Examples:"
        echo "  ./manage-wp.sh plugins                    # List all plugins"
        echo "  ./manage-wp.sh plugin-status              # Check WebP Migrator status"
        echo "  ./manage-wp.sh activate                   # Activate WebP Migrator"
        echo "  ./manage-wp.sh activate query-monitor     # Activate Query Monitor plugin"
        echo "  ./manage-wp.sh wp-info                    # WordPress version info"
        echo "  ./manage-wp.sh cache-flush                # Clear caches"
        echo "  ./manage-wp.sh shell                      # Open WP-CLI shell"
        echo ""
        ;;
esac
