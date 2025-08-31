#!/bin/bash
# WordPress Auto-Installation Script with Health Checks
# Ensures consistent WordPress setup across all platforms
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

log_info() { echo -e "${CYAN}[WP-INSTALL]${NC} $1"; }
log_success() { echo -e "${GREEN}[WP-INSTALL]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WP-INSTALL]${NC} $1"; }
log_error() { echo -e "${RED}[WP-INSTALL]${NC} $1"; }

# Default values (can be overridden by environment variables)
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASS="${WP_ADMIN_PASS:-admin123}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@webp-test.local}"
WP_SITE_TITLE="${WP_SITE_TITLE:-WebP Migrator Test Site}"
WP_SITE_URL="${WP_SITE_URL:-http://localhost:8080}"
CONTAINER_ENGINE="${CONTAINER_ENGINE:-docker}"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"

log_info "Starting WordPress auto-installation..."
log_info "Admin User: $WP_ADMIN_USER"
log_info "Site URL: $WP_SITE_URL"
log_info "Container Engine: $CONTAINER_ENGINE"

# Get compose command
if [[ "$CONTAINER_ENGINE" == "podman" ]]; then
    if command -v podman-compose >/dev/null 2>&1; then
        COMPOSE_CMD="podman-compose -f $COMPOSE_FILE"
    else
        COMPOSE_CMD="podman compose -f $COMPOSE_FILE"
    fi
else
    if command -v docker-compose >/dev/null 2>&1; then
        COMPOSE_CMD="docker-compose -f $COMPOSE_FILE"
    else
        COMPOSE_CMD="docker compose -f $COMPOSE_FILE"
    fi
fi

# Wait for services to be healthy
log_info "Waiting for services to be healthy..."
for i in {1..60}; do
    if $COMPOSE_CMD ps | grep -q "healthy"; then
        healthy_count=$($COMPOSE_CMD ps | grep -c "healthy" || echo 0)
        if [[ $healthy_count -ge 2 ]]; then # WordPress + Database should be healthy
            log_success "Services are healthy!"
            break
        fi
    fi
    
    if [[ $i -eq 60 ]]; then
        log_warning "Services not all healthy after 5 minutes, proceeding anyway..."
        break
    fi
    
    if [[ $((i % 10)) -eq 0 ]]; then
        log_info "Still waiting for services... ($i/60)"
    fi
    sleep 5
done

# Additional WordPress readiness check
log_info "Verifying WordPress is responding..."
for i in {1..30}; do
    if curl -s -o /dev/null -w "%{http_code}" "$WP_SITE_URL" | grep -q "200\|30[0-9]"; then
        log_success "WordPress is responding!"
        break
    fi
    
    if [[ $i -eq 30 ]]; then
        log_warning "WordPress may not be fully ready, but proceeding with installation..."
        break
    fi
    
    if [[ $((i % 5)) -eq 0 ]]; then
        log_info "Waiting for WordPress to respond... ($i/30)"
    fi
    sleep 3
done

# Check if WordPress is already installed
log_info "Checking WordPress installation status..."
if $COMPOSE_CMD exec -T wpcli wp core is-installed 2>/dev/null; then
    log_info "WordPress is already installed"
    
    # Check if admin user exists
    if $COMPOSE_CMD exec -T wpcli wp user get "$WP_ADMIN_USER" 2>/dev/null >/dev/null; then
        log_info "Admin user '$WP_ADMIN_USER' exists, updating password..."
        $COMPOSE_CMD exec -T wpcli wp user update "$WP_ADMIN_USER" --user_pass="$WP_ADMIN_PASS"
        log_success "Admin user password updated"
    else
        log_info "Creating admin user '$WP_ADMIN_USER'..."
        $COMPOSE_CMD exec -T wpcli wp user create "$WP_ADMIN_USER" "$WP_ADMIN_EMAIL" \
            --role=administrator --user_pass="$WP_ADMIN_PASS"
        log_success "Admin user created"
    fi
else
    # Install WordPress
    log_info "Installing WordPress..."
    if $COMPOSE_CMD exec -T wpcli wp core install \
        --url="$WP_SITE_URL" \
        --title="$WP_SITE_TITLE" \
        --admin_user="$WP_ADMIN_USER" \
        --admin_password="$WP_ADMIN_PASS" \
        --admin_email="$WP_ADMIN_EMAIL" \
        --skip-email; then
        
        log_success "WordPress installed successfully!"
    else
        log_error "WordPress installation failed!"
        exit 1
    fi
fi

# Activate WebP Safe Migrator plugin
log_info "Activating WebP Safe Migrator plugin..."
if $COMPOSE_CMD exec -T wpcli wp plugin activate webp-safe-migrator 2>/dev/null; then
    log_success "Plugin activated successfully!"
else
    log_warning "Plugin activation failed - you can activate it manually in WordPress admin"
fi

# Create test content if needed
log_info "Setting up test content..."
test_page_exists=$($COMPOSE_CMD exec -T wpcli wp post list --post_type=page --title="WebP Migrator Test Guide" --format=count 2>/dev/null || echo 0)
if [[ "$test_page_exists" -eq 0 ]]; then
    log_info "Creating test page..."
    $COMPOSE_CMD exec -T wpcli wp post create \
        --post_type=page \
        --post_title="WebP Migrator Test Guide" \
        --post_content="<h2>Welcome to $WP_SITE_TITLE</h2><p>Go to Media → WebP Migrator to start testing.</p><p><strong>Login Credentials:</strong><br>Username: $WP_ADMIN_USER<br>Password: $WP_ADMIN_PASS</p>" \
        --post_status=publish 2>/dev/null || log_warning "Could not create test page"
else
    log_info "Test page already exists"
fi

# Final verification
log_info "Final verification..."
if curl -s -o /dev/null -w "%{http_code}" "$WP_SITE_URL/wp-admin/" | grep -q "200"; then
    log_success "WordPress admin is accessible!"
else
    log_warning "WordPress admin may not be fully ready yet"
fi

echo ""
log_success "=== WordPress Installation Complete ==="
echo ""
echo "Site URL: $WP_SITE_URL"
echo "Admin Panel: $WP_SITE_URL/wp-admin"
echo ""
echo "Login Credentials:"
echo "  Username: $WP_ADMIN_USER"
echo "  Password: $WP_ADMIN_PASS"
echo "  Email: $WP_ADMIN_EMAIL"
echo ""
echo "Plugin: WebP Safe Migrator (should be activated)"
echo "Go to: Media → WebP Migrator to start testing"
echo ""
