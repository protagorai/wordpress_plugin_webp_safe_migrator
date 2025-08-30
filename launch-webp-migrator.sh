#!/bin/bash
# ==============================================================================
# WebP Safe Migrator - Linux/macOS Launcher
# Simple deployment script for Unix systems
# ==============================================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo ""
echo "====================================="
echo "  WebP Safe Migrator Launcher"
echo "====================================="
echo ""

# Check if we're in the right directory
if [[ ! -f "src/webp-safe-migrator.php" ]]; then
    echo -e "${RED}ERROR: Please run this script from the project root directory.${NC}"
    echo "Expected to find: src/webp-safe-migrator.php"
    echo "Current directory: $(pwd)"
    exit 1
fi

# Check if Podman is available
echo "Checking Podman..."
if ! command -v podman >/dev/null 2>&1; then
    echo -e "${RED}ERROR: Podman not found. Please install Podman first.${NC}"
    echo "Install from: https://podman.io/getting-started/installation"
    exit 1
fi
echo -e "${GREEN}✓ Podman is available${NC}"

echo ""
echo "Starting WebP Safe Migrator deployment..."
echo ""

# Clean up existing containers first
echo "Cleaning up existing containers..."
podman stop webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin webp-migrator-wpcli 2>/dev/null || true
podman rm -f webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin webp-migrator-wpcli 2>/dev/null || true
podman network rm webp-migrator-net 2>/dev/null || true

echo -e "${GREEN}✓ Cleanup completed${NC}"
echo ""

# Create network
echo "Creating container network..."
podman network create webp-migrator-net
if [[ $? -ne 0 ]]; then
    echo -e "${RED}ERROR: Failed to create network${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Network created${NC}"

echo ""

# Start MySQL
echo "Starting MySQL database..."
podman run -d \
    --name webp-migrator-mysql \
    --network webp-migrator-net \
    -p 3307:3306 \
    -e MYSQL_DATABASE=wordpress_webp_test \
    -e MYSQL_USER=wordpress \
    -e MYSQL_PASSWORD=wordpress123 \
    -e MYSQL_ROOT_PASSWORD=root123 \
    docker.io/library/mysql:8.0 \
    --default-authentication-plugin=mysql_native_password

if [[ $? -ne 0 ]]; then
    echo -e "${RED}ERROR: Failed to start MySQL${NC}"
    exit 1
fi
echo -e "${GREEN}✓ MySQL container started${NC}"

echo ""
echo "Waiting for MySQL to be ready (45 seconds)..."
sleep 45

echo "Testing MySQL connection..."
if ! podman exec webp-migrator-mysql mysqladmin ping -u root -proot123 >/dev/null 2>&1; then
    echo "MySQL not ready yet, waiting more..."
    sleep 15
    if ! podman exec webp-migrator-mysql mysqladmin ping -u root -proot123 >/dev/null 2>&1; then
        echo "Still waiting for MySQL..."
        sleep 15
        if ! podman exec webp-migrator-mysql mysqladmin ping -u root -proot123 >/dev/null 2>&1; then
            echo -e "${RED}ERROR: MySQL not ready after extended wait${NC}"
            echo "You can check MySQL logs with: podman logs webp-migrator-mysql"
            exit 1
        fi
    fi
fi
echo -e "${GREEN}✓ MySQL is ready!${NC}"

echo ""

# Start WordPress
echo "Starting WordPress..."
podman run -d \
    --name webp-migrator-wordpress \
    --network webp-migrator-net \
    -p 8080:80 \
    -e WORDPRESS_DB_HOST=webp-migrator-mysql \
    -e WORDPRESS_DB_USER=wordpress \
    -e WORDPRESS_DB_PASSWORD=wordpress123 \
    -e WORDPRESS_DB_NAME=wordpress_webp_test \
    -e WORDPRESS_DEBUG=1 \
    -v "$(pwd)/src:/var/www/html/wp-content/plugins/webp-safe-migrator" \
    docker.io/library/wordpress:latest

if [[ $? -ne 0 ]]; then
    echo -e "${RED}ERROR: Failed to start WordPress${NC}"
    exit 1
fi
echo -e "${GREEN}✓ WordPress container started${NC}"

echo ""

# Start phpMyAdmin
echo "Starting phpMyAdmin..."
podman run -d \
    --name webp-migrator-phpmyadmin \
    --network webp-migrator-net \
    -p 8081:80 \
    -e PMA_HOST=webp-migrator-mysql \
    -e PMA_USER=root \
    -e PMA_PASSWORD=root123 \
    docker.io/library/phpmyadmin:latest

if [[ $? -ne 0 ]]; then
    echo -e "${RED}ERROR: Failed to start phpMyAdmin${NC}"
    exit 1
fi
echo -e "${GREEN}✓ phpMyAdmin container started${NC}"

echo ""
echo "Waiting for WordPress to be ready (60 seconds)..."
echo "This is normal - WordPress needs time to download and setup..."
sleep 60

echo "Testing WordPress connection with detailed diagnostics..."
echo "* Site URL: http://localhost:8080"
echo "* Expected response: 200 or 30x redirect codes"
echo ""

# First, check if the container is actually running
echo -e "${CYAN}[DIAGNOSTIC]${NC} Checking container status..."
podman ps --filter "name=webp-migrator-wordpress" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

# Check if port 8080 is accessible
echo -e "${CYAN}[DIAGNOSTIC]${NC} Testing port accessibility..."
if ! netstat -an | grep -q ":8080"; then
    echo -e "${YELLOW}! WARNING: Port 8080 not found in netstat - container port binding may have failed${NC}"
else
    echo -e "${GREEN}* Port 8080 is bound and listening${NC}"
fi

echo ""
echo -e "${CYAN}[DIAGNOSTIC]${NC} Checking WordPress container logs for errors..."
podman logs webp-migrator-wordpress --tail=20 2>/dev/null || echo -e "${YELLOW}! Could not retrieve container logs${NC}"

echo ""
echo -e "${CYAN}[DIAGNOSTIC]${NC} Testing database connectivity from WordPress container..."
if podman exec webp-migrator-wordpress mysql -h webp-migrator-mysql -u wordpress -pwordpress123 -e "SELECT 'Database connection: OK';" 2>/dev/null; then
    echo -e "${GREEN}* Database connection successful${NC}"
else
    echo -e "${RED}! Database connection failed${NC}"
fi

echo ""
echo -e "${CYAN}[CONNECTION TEST]${NC} Testing WordPress response..."
for i in {1..10}; do
    echo "* Attempt $i/10: Testing http://localhost:8080"
    
    # Get detailed response information
    response=$(curl -s -o /dev/null -w "HTTP_CODE:%{http_code} TIME:%{time_total}s SIZE:%{size_download}bytes" "http://localhost:8080" 2>/dev/null)
    echo "  Response: $response"
    
    if echo "$response" | grep -q "HTTP_CODE:200\|HTTP_CODE:30"; then
        echo -e "${GREEN}* SUCCESS: WordPress is responding! $response${NC}"
        break
    fi
    
    # Show what HTTP code we got
    http_code=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080" 2>/dev/null)
    if [[ "$http_code" != "000" ]]; then
        echo -e "${YELLOW}  Got HTTP $http_code instead of 200/30x${NC}"
    else
        echo -e "${RED}  Connection refused or timeout${NC}"
    fi
    
    if [[ $i -lt 10 ]]; then
        echo "  Waiting 10 seconds before retry..."
        sleep 10
    fi
done

echo ""
echo -e "${CYAN}[FINAL DIAGNOSTIC]${NC} After 10 attempts, showing detailed status:"
if ! podman exec webp-migrator-wordpress ps aux | grep -q apache; then
    echo -e "${RED}! Apache processes not found in container${NC}"
else
    echo -e "${GREEN}* Apache processes running${NC}"
fi

if ! podman exec webp-migrator-wordpress ls -la /var/www/html/ 2>/dev/null | head -5; then
    echo -e "${RED}! WordPress files not accessible${NC}"
fi

echo -e "${GREEN}✓ WordPress connection testing completed${NC}"

echo ""

# Configure PHP for optimal WebP processing
echo "Configuring PHP upload limits..."
podman exec webp-migrator-wordpress bash -c "echo 'upload_max_filesize = 128M' > /usr/local/etc/php/conf.d/webp-migrator.ini && echo 'post_max_size = 128M' >> /usr/local/etc/php/conf.d/webp-migrator.ini && echo 'max_execution_time = 300' >> /usr/local/etc/php/conf.d/webp-migrator.ini && echo 'memory_limit = 512M' >> /usr/local/etc/php/conf.d/webp-migrator.ini"

echo "Restarting Apache to apply PHP configuration..."
podman exec webp-migrator-wordpress apache2ctl graceful 2>/dev/null

# Install WP-CLI in WordPress container
echo "Installing WP-CLI in WordPress container..."
podman exec webp-migrator-wordpress bash -c "curl -o /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /tmp/wp-cli.phar && mv /tmp/wp-cli.phar /usr/local/bin/wp" 2>/dev/null

echo ""

# Install WordPress
echo "Installing WordPress..."
if podman exec webp-migrator-wordpress wp core install \
    --url="http://localhost:8080" \
    --title="WebP Migrator Test Site" \
    --admin_user="admin" \
    --admin_password="admin123" \
    --admin_email="admin@webp-test.local" \
    --locale="en_US" \
    --skip-email \
    --allow-root; then
    echo -e "${GREEN}✓ WordPress installed successfully!${NC}"
else
    echo -e "${YELLOW}! WordPress installation had issues - you may need to complete setup manually${NC}"
    echo "  Go to http://localhost:8080 to finish WordPress setup"
fi

echo ""

# Activate plugin
echo "Activating WebP Safe Migrator plugin..."
if podman exec webp-migrator-wordpress wp plugin activate webp-safe-migrator --allow-root 2>/dev/null; then
    echo -e "${GREEN}✓ Plugin activated successfully!${NC}"
else
    echo -e "${YELLOW}! Plugin activation failed - you can activate it manually in WordPress admin${NC}"
    echo "  Go to Plugins → Installed Plugins and activate WebP Safe Migrator"
fi

echo ""

# Create sample content
echo "Creating sample content..."
podman exec webp-migrator-wordpress wp post create \
    --post_type=page \
    --post_title="WebP Migrator Test Guide" \
    --post_content="<h2>Welcome to WebP Safe Migrator Test Site</h2><p>Go to Media → WebP Migrator to start testing.</p>" \
    --post_status=publish \
    --allow-root 2>/dev/null

echo -e "${GREEN}✓ Sample content created${NC}"

echo ""
echo "Checking final container status..."
podman ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

echo ""
echo "====================================="
echo "  DEPLOYMENT COMPLETE!"
echo "====================================="
echo ""
echo -e "${CYAN}✓ WordPress Site: http://localhost:8080${NC}"
echo -e "${CYAN}✓ WordPress Admin: http://localhost:8080/wp-admin${NC}"
echo -e "${CYAN}✓ phpMyAdmin: http://localhost:8081${NC}"
echo ""
echo "WordPress Credentials:"
echo "  Username: admin"
echo "  Password: admin123"
echo ""
echo "Database Credentials:"
echo "  Database: wordpress_webp_test"
echo "  User: wordpress / wordpress123"
echo "  Root: root / root123"
echo ""
echo "Plugin Access:"
echo "  Go to Media → WebP Migrator in WordPress admin"
echo ""

# Open WordPress in default browser (platform-specific)
echo "Opening WordPress in browser..."
case "$(uname -s)" in
    Darwin*)
        open "http://localhost:8080"
        ;;
    Linux*)
        if command -v xdg-open >/dev/null; then
            xdg-open "http://localhost:8080"
        elif command -v gnome-open >/dev/null; then
            gnome-open "http://localhost:8080"
        else
            echo "Please manually open: http://localhost:8080"
        fi
        ;;
    *)
        echo "Please manually open: http://localhost:8080"
        ;;
esac

echo ""
echo -e "${GREEN}SUCCESS! WordPress with WebP Safe Migrator is ready!${NC}"
echo ""
echo "Management Scripts Available:"
echo "  ./launch-webp-migrator.sh        - Start/restart (this script)"
echo "  ./stop-webp-migrator.sh          - Stop containers (keep data)"
echo "  ./cleanup-webp-migrator.sh       - Complete cleanup (removes all data)"
echo "  ./status-webp-migrator.sh        - Show status"
echo "  ./manage-wp.sh                   - WordPress management commands"
echo ""
echo "Script completed successfully!"
