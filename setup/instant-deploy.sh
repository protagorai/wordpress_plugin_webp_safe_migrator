#!/bin/bash
# WebP Safe Migrator - Instant One-Click Deploy
# Just run this and everything happens automatically!

echo "🚀 WebP Safe Migrator - Instant Deploy"
echo "Starting complete automated setup..."
echo ""

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Run the complete deployment script
if [[ -f "$SCRIPT_DIR/webp-migrator-deploy.sh" ]]; then
    chmod +x "$SCRIPT_DIR/webp-migrator-deploy.sh"
    "$SCRIPT_DIR/webp-migrator-deploy.sh" --clean-start
    
    if [[ $? -eq 0 ]]; then
        echo ""
        echo "🎉 SUCCESS! WebP Safe Migrator is ready!"
        echo "WordPress should be accessible at http://localhost:8080"
        echo "Admin: http://localhost:8080/wp-admin (admin / admin123!)"
    fi
else
    echo "❌ Setup script not found!"
fi

