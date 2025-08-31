#!/bin/bash
# ==============================================================================
# WebP Safe Migrator - Upload Ownership Fix Utility (Linux/macOS)
# Final fallback to fix upload permissions when other methods fail
# ==============================================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

echo ""
echo -e "${GREEN}=====================================${NC}"
echo -e "${GREEN}  Upload Ownership Fix Utility${NC}"
echo -e "${GREEN}=====================================${NC}"
echo ""

# Check if Podman is available
echo "Checking Podman availability..."
if ! command -v podman >/dev/null 2>&1; then
    echo -e "${RED}ERROR: Podman not found. Please install Podman first.${NC}"
    echo ""
    echo "Installation guides:"
    echo "  Linux: https://podman.io/getting-started/installation#linux"
    echo "  macOS: https://podman.io/getting-started/installation#macos"
    echo "  Or try: sudo apt install podman  # Ubuntu/Debian"
    echo "         brew install podman      # macOS"
    echo ""
    read -p "Press Enter to exit..."
    exit 1
fi
echo -e "${GREEN}* Podman is available${NC}"

# Check if WordPress container is running
echo "Checking if WordPress container is running..."
if ! podman ps --format "{{.Names}}" | grep -q webp-migrator-wordpress; then
    echo -e "${RED}ERROR: WordPress container not running${NC}"
    echo "Please run './webp-migrator-simple.sh' first to start containers"
    read -p "Press Enter to exit..."
    exit 1
fi
echo -e "${GREEN}* WordPress container is running${NC}"

echo ""
echo "Fixing WordPress upload permissions..."
echo ""

# Show current ownership
echo "Current ownership status:"
podman exec webp-migrator-wordpress bash -c "echo 'wp-content/: ' && stat -c '%U:%G' /var/www/html/wp-content/"
podman exec webp-migrator-wordpress bash -c "echo 'uploads/: ' && stat -c '%U:%G' /var/www/html/wp-content/uploads/ 2>/dev/null || echo 'uploads/: directory not found'"
podman exec webp-migrator-wordpress bash -c "echo 'uploads/2025/: ' && stat -c '%U:%G' /var/www/html/wp-content/uploads/2025/ 2>/dev/null || echo 'uploads/2025/: not created yet'"

echo ""
echo "Applying ownership fix..."

# Comprehensive ownership fix with detailed logging
podman exec webp-migrator-wordpress bash -c "
echo '[MANUAL-OWNERSHIP-FIX] Starting comprehensive ownership repair...'

# Show before state  
echo '[MANUAL-OWNERSHIP-FIX] BEFORE - wp-content owned by:' \$(stat -c '%U:%G' /var/www/html/wp-content/ 2>/dev/null)
echo '[MANUAL-OWNERSHIP-FIX] BEFORE - uploads owned by:' \$(stat -c '%U:%G' /var/www/html/wp-content/uploads/ 2>/dev/null || echo 'not found')

# Fix ALL wp-content and nested directories
chown -R www-data:www-data /var/www/html/wp-content/
find /var/www/html/wp-content -type d -exec chmod 755 {} \;
find /var/www/html/wp-content -type f -exec chmod 644 {} \;

# Show after state
echo '[MANUAL-OWNERSHIP-FIX] AFTER - wp-content owned by:' \$(stat -c '%U:%G' /var/www/html/wp-content/)
echo '[MANUAL-OWNERSHIP-FIX] AFTER - uploads owned by:' \$(stat -c '%U:%G' /var/www/html/wp-content/uploads/)
echo '[MANUAL-OWNERSHIP-FIX] AFTER - uploads/2025 owned by:' \$(stat -c '%U:%G' /var/www/html/wp-content/uploads/2025/ 2>/dev/null || echo 'not created yet')

echo '[MANUAL-OWNERSHIP-FIX] Manual ownership fix complete!'
"

echo ""
echo "Comprehensive ownership fix applied:"
podman exec webp-migrator-wordpress bash -c "echo 'wp-content/: ' && stat -c '%U:%G' /var/www/html/wp-content/"
podman exec webp-migrator-wordpress bash -c "echo 'uploads/: ' && stat -c '%U:%G' /var/www/html/wp-content/uploads/"
podman exec webp-migrator-wordpress bash -c "echo 'uploads/2025/: ' && stat -c '%U:%G' /var/www/html/wp-content/uploads/2025/ 2>/dev/null || echo 'uploads/2025/: not created yet'"

echo ""
echo -e "${GREEN}=====================================${NC}"
echo -e "${GREEN}  Upload Ownership Fix Complete!${NC}"
echo -e "${GREEN}=====================================${NC}"
echo ""
echo -e "${GREEN}* wp-content/ and all subdirectories now owned by www-data${NC}"
echo -e "${GREEN}* File uploads should work correctly now${NC}"
echo -e "${GREEN}* Try uploading images through WordPress admin${NC}"
echo ""
echo "If upload issues persist:"
echo "  1. Run this script again: ./fix-uploads-ownership.sh"
echo "  2. Check container logs: podman logs webp-migrator-wordpress"
echo "  3. Restart containers: ./webp-migrator-simple.sh clean && ./webp-migrator-simple.sh"
echo ""
read -p "Press Enter to close..."
