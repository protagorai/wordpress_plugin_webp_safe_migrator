#!/bin/bash
# ==============================================================================
# WebP Safe Migrator - Elegant Minimal Solution  
# One script that just works - no complexity, no confusion
# ==============================================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

if [[ "$1" == "stop" ]]; then
    echo "Stopping all containers..."
    podman stop webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin 2>/dev/null || true
    echo "Done."
    exit 0
fi

if [[ "$1" == "clean" ]]; then
    echo "Removing all containers and data..."
    podman stop webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin 2>/dev/null || true
    podman rm -f webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin 2>/dev/null || true
    podman network rm webp-migrator-net 2>/dev/null || true
    echo "Clean complete."
    exit 0
fi

if [[ "$1" == "status" ]]; then
    echo "Container Status:"
    podman ps --filter name=webp-migrator
    exit 0
fi

echo ""
echo -e "${GREEN}=====================================${NC}"
echo -e "${GREEN}   WebP Safe Migrator - Simple${NC}"
echo -e "${GREEN}=====================================${NC}"
echo ""

# Check if Podman is available
echo "Checking system requirements..."
if ! command -v podman >/dev/null 2>&1; then
    echo -e "${RED}ERROR: Podman not found. This tool requires Podman to be installed.${NC}"
    echo ""
    echo "Installation guides by operating system:"
    echo "  Linux: https://podman.io/getting-started/installation#linux"
    echo "  macOS: https://podman.io/getting-started/installation#macos"
    echo "  Ubuntu/Debian: sudo apt install podman"
    echo "  RHEL/CentOS: sudo dnf install podman"
    echo "  macOS: brew install podman"
    echo ""
    echo "Alternative: You can also use Docker if available"
    echo "  Just replace 'podman' with 'docker' in the scripts"
    echo ""
    read -p "Press Enter to exit..."
    exit 1
fi
podman --version
echo -e "${GREEN}* Podman is available and ready${NC}"
echo ""

# Clean start
echo "Cleaning up any existing containers..."
podman stop webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin 2>/dev/null || true
podman rm -f webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin 2>/dev/null || true
podman network rm webp-migrator-net 2>/dev/null || true

# Create network
echo "Creating network..."
podman network create webp-migrator-net >/dev/null
if [[ $? -ne 0 ]]; then
    echo -e "${RED}ERROR: Failed to create network. Is Podman running?${NC}"
    read -p "Press Enter to exit..."
    exit 1
fi

# Start database
echo "Starting database..."
podman run -d --name webp-migrator-mysql --network webp-migrator-net \
    -p 3307:3306 \
    -e MYSQL_ROOT_PASSWORD=root123 \
    -e MYSQL_DATABASE=wordpress \
    -e MYSQL_USER=wpuser \
    -e MYSQL_PASSWORD=wppass \
    mysql:8.0 --default-authentication-plugin=mysql_native_password >/dev/null

# Wait for database
echo "Waiting for database (30 seconds)..."
sleep 30

# Start WordPress with proper database connection
echo "Starting WordPress..."
podman run -d --name webp-migrator-wordpress --network webp-migrator-net \
    -p 8080:80 \
    -e WORDPRESS_DB_HOST=webp-migrator-mysql \
    -e WORDPRESS_DB_USER=wpuser \
    -e WORDPRESS_DB_PASSWORD=wppass \
    -e WORDPRESS_DB_NAME=wordpress \
    -v "$(pwd)/src:/var/www/html/wp-content/plugins/webp-safe-migrator" \
    wordpress:latest >/dev/null

# Start phpMyAdmin
echo "Starting phpMyAdmin..."
podman run -d --name webp-migrator-phpmyadmin --network webp-migrator-net \
    -p 8081:80 \
    -e PMA_HOST=webp-migrator-mysql \
    -e PMA_USER=root \
    -e PMA_PASSWORD=root123 \
    phpmyadmin:latest >/dev/null

# Wait for WordPress to initialize fully  
echo "Waiting for WordPress (90 seconds)..."
sleep 90

# Install WordPress via WP-CLI
echo "Installing WordPress and plugin..."
podman exec webp-migrator-wordpress bash -c "curl -sS https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp && chmod +x /usr/local/bin/wp" 2>/dev/null

podman exec --user www-data webp-migrator-wordpress wp core install \
    --url="http://localhost:8080" \
    --title="WebP Migrator Test" \
    --admin_user="admin" \
    --admin_password="admin123" \
    --admin_email="admin@test.local" \
    --skip-email 2>/dev/null

# Fix WordPress configuration to prevent SSL warnings
echo "Configuring WordPress for container environment..."
podman exec webp-migrator-wordpress wp config set WP_HTTP_BLOCK_EXTERNAL true --allow-root 2>/dev/null
podman exec webp-migrator-wordpress wp config set WP_ACCESSIBLE_HOSTS 'localhost,127.0.0.1' --allow-root 2>/dev/null
podman exec webp-migrator-wordpress wp config set AUTOMATIC_UPDATER_DISABLED true --allow-root 2>/dev/null
podman exec webp-migrator-wordpress wp config set WP_DEBUG_DISPLAY false --allow-root 2>/dev/null
echo -e "${GREEN}* WordPress configuration fixed - no more SSL warnings${NC}"

# Check if WordPress install was successful
if podman exec webp-migrator-wordpress wp core is-installed --allow-root 2>/dev/null; then
    
    # CRITICAL: Fix ownership AFTER WordPress installation completes
    echo "Fixing WordPress ownership after installation..."
    podman exec webp-migrator-wordpress chown -R www-data:www-data /var/www/html/wp-content/ 2>/dev/null
    podman exec webp-migrator-wordpress find /var/www/html/wp-content -type d -exec chmod 755 {} \; 2>/dev/null
    podman exec webp-migrator-wordpress find /var/www/html/wp-content -type f -exec chmod 644 {} \; 2>/dev/null
    echo -e "${GREEN}* WordPress ownership fixed AFTER installation - uploads will work correctly${NC}"
    
    # Activate plugin
    podman exec --user www-data webp-migrator-wordpress wp plugin activate webp-safe-migrator 2>/dev/null || true
    
    echo ""
    echo -e "${GREEN}=====================================${NC}"
    echo -e "${GREEN}    SUCCESS - WebP Migrator Ready!${NC}"
    echo -e "${GREEN}=====================================${NC}"
    echo ""
    echo -e "${CYAN} WordPress: http://localhost:8080/wp-admin${NC}"
    echo -e "${CYAN} Username:  admin${NC}"
    echo -e "${CYAN} Password:  admin123${NC}"
    echo ""
    echo -e "${CYAN} phpMyAdmin: http://localhost:8081${NC}"
    echo -e "${CYAN} Plugin: Media → WebP Migrator${NC}"
    echo ""
    
    # Open browser
    case "$(uname -s)" in
        Darwin*) open "http://localhost:8080/wp-admin" ;;
        Linux*) xdg-open "http://localhost:8080/wp-admin" 2>/dev/null || true ;;
    esac
    
else
    echo ""
    echo -e "${YELLOW}WordPress auto-install failed - please complete setup manually:${NC}"
    echo ""
    echo "  Go to: http://localhost:8080"
    echo "  Database: wordpress"
    echo "  User: wpuser"
    echo "  Password: wppass"
    echo "  Host: webp-migrator-mysql"
    echo ""
    echo "Then create admin user: admin / admin123"
    echo ""
fi

REM FINAL COMPREHENSIVE OWNERSHIP FIX - After ALL setup is complete
echo ""
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE} FINAL OWNERSHIP FIX (COMPREHENSIVE)${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""
echo "Applying final WordPress ownership fix..."
echo "This ensures uploads work correctly after complete setup."
echo ""

# Final comprehensive ownership fix with simple commands
echo "[FINAL-FIX] Applying comprehensive ownership fix..."
podman exec webp-migrator-wordpress chown -R www-data:www-data /var/www/html/wp-content/ 2>/dev/null
podman exec webp-migrator-wordpress find /var/www/html/wp-content -type d -exec chmod 755 {} \; 2>/dev/null
podman exec webp-migrator-wordpress find /var/www/html/wp-content -type f -exec chmod 644 {} \; 2>/dev/null
echo "[FINAL-FIX] WordPress ownership fix complete"

echo -e "${GREEN}* Final ownership fix applied - uploads will work correctly${NC}"

# Activate plugin AFTER final comprehensive ownership fix
echo "[FINAL-FIX] Activating WebP Safe Migrator plugin after final ownership fix..."
if podman exec webp-migrator-wordpress wp plugin activate webp-safe-migrator --allow-root 2>/dev/null; then
    echo -e "${GREEN}✓ Plugin activated successfully after final ownership fix!${NC}"
else
    echo -e "${YELLOW}! Final plugin activation failed - you can activate it manually${NC}"
fi

echo ""

echo "Commands:"
echo "  ./webp-migrator-simple.sh         (start)"
echo "  ./webp-migrator-simple.sh stop    (stop)"
echo "  ./webp-migrator-simple.sh clean   (clean)"
echo "  ./bin/manage/fix-uploads-ownership.sh   (fix upload permissions)"
echo ""
echo -e "${GREEN}NOTE: Upload permissions have been fixed automatically${NC}"
echo ""
read -p "Press Enter to close..."
