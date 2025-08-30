#!/bin/bash
# ==============================================================================
# WebP Safe Migrator - Universal Launcher
# Auto-detects platform and runs appropriate script
# ==============================================================================

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo ""
echo "====================================="
echo "  WebP Safe Migrator Universal Launcher"
echo "====================================="
echo ""

# Detect platform
if [[ -n "$WSL_DISTRO_NAME" ]] || [[ "$(uname -r)" == *microsoft* ]]; then
    PLATFORM="wsl"
    echo -e "${CYAN}Detected Windows WSL environment${NC}"
elif [[ "$OSTYPE" == "darwin"* ]]; then
    PLATFORM="macos"
    echo -e "${CYAN}Detected macOS environment${NC}"
else
    PLATFORM="linux"
    echo -e "${CYAN}Detected Linux environment${NC}"
fi

echo ""

# Check if we're in the right directory
if [[ ! -f "src/webp-safe-migrator.php" ]]; then
    echo -e "${RED}ERROR: Please run this script from the project root directory.${NC}"
    echo "Expected to find: src/webp-safe-migrator.php"
    echo "Current directory: $(pwd)"
    exit 1
fi

# Run the appropriate launcher
echo "Running platform-specific launcher..."
if [[ -f "./launch-webp-migrator.sh" ]]; then
    echo -e "${GREEN}Using Linux/macOS launcher: ./launch-webp-migrator.sh${NC}"
    chmod +x ./launch-webp-migrator.sh 2>/dev/null || true
    ./launch-webp-migrator.sh
else
    echo -e "${RED}ERROR: launch-webp-migrator.sh not found${NC}"
    echo "Please ensure all launcher scripts are present."
    exit 1
fi
