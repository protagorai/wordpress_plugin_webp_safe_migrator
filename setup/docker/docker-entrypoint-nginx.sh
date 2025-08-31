#!/bin/bash
# WebP Safe Migrator - Docker Entrypoint for Nginx + PHP-FPM with SSL Support
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

log_info() {
    echo -e "${CYAN}[NGINX-SSL-SETUP]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[NGINX-SSL-SETUP]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[NGINX-SSL-SETUP]${NC} $1"
}

log_error() {
    echo -e "${RED}[NGINX-SSL-SETUP]${NC} $1"
}

# Generate self-signed SSL certificate if not exists
generate_ssl_cert() {
    local cert_dir="/etc/ssl/certs/webp-migrator"
    local cert_file="$cert_dir/cert.pem"
    local key_file="$cert_dir/key.pem"
    
    if [[ ! -f "$cert_file" ]] || [[ ! -f "$key_file" ]]; then
        log_info "Generating self-signed SSL certificate..."
        
        mkdir -p "$cert_dir"
        
        # Generate private key
        openssl genrsa -out "$key_file" 2048
        
        # Generate certificate
        openssl req -new -x509 -key "$key_file" -out "$cert_file" -days 365 -subj \
            "/C=US/ST=Development/L=Local/O=WebP Migrator/OU=Development/CN=${CUSTOM_DOMAIN:-localhost}/emailAddress=dev@webp-migrator.local" \
            -addext "subjectAltName=DNS:${CUSTOM_DOMAIN:-localhost},DNS:webp-migrator.local,DNS:*.webp-migrator.local,IP:127.0.0.1"
        
        # Set proper permissions
        chmod 600 "$key_file"
        chmod 644 "$cert_file"
        chown root:root "$cert_file" "$key_file"
        
        log_success "SSL certificate generated successfully"
    else
        log_info "SSL certificate already exists"
    fi
}

# Update Nginx configuration with custom domain
update_nginx_config() {
    local config_file="/etc/nginx/sites-available/webp-migrator"
    local custom_domain="${CUSTOM_DOMAIN:-localhost}"
    
    if [[ "$custom_domain" != "localhost" ]]; then
        log_info "Updating Nginx configuration for custom domain: $custom_domain"
        
        # Update server_name in both HTTP and HTTPS blocks
        sed -i "s/server_name localhost webp-migrator.local;/server_name $custom_domain localhost webp-migrator.local;/g" "$config_file"
        
        log_success "Nginx configuration updated for custom domain"
    fi
}

# Setup Let's Encrypt directory structure
setup_letsencrypt() {
    log_info "Setting up Let's Encrypt directory structure..."
    
    mkdir -p /var/www/html/.well-known/acme-challenge
    chown -R www-data:www-data /var/www/html/.well-known
    chmod -R 755 /var/www/html/.well-known
    
    log_success "Let's Encrypt directory structure ready"
}

# Configure WordPress for SSL
configure_wordpress_ssl() {
    local wp_config="/var/www/html/wp-config.php"
    
    if [[ -f "$wp_config" ]]; then
        log_info "Configuring WordPress for SSL..."
        
        # Add SSL configuration to wp-config.php if not already present
        if ! grep -q "FORCE_SSL_ADMIN" "$wp_config"; then
            sed -i "/\/\* That's all, stop editing/i\\
\\
/* SSL Configuration */\\
define('FORCE_SSL_ADMIN', true);\\
if (isset(\$_SERVER['HTTP_X_FORWARDED_PROTO']) && \$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {\\
    \$_SERVER['HTTPS'] = 'on';\\
}\\
if (isset(\$_SERVER['HTTP_X_FORWARDED_HOST'])) {\\
    \$_SERVER['HTTP_HOST'] = \$_SERVER['HTTP_X_FORWARDED_HOST'];\\
}\\
" "$wp_config"
            
            log_success "WordPress SSL configuration added"
        else
            log_info "WordPress SSL configuration already present"
        fi
    else
        log_warning "wp-config.php not found, SSL configuration will be added later"
    fi
}

# Test Nginx configuration
test_nginx_config() {
    log_info "Testing Nginx configuration..."
    
    if nginx -t; then
        log_success "Nginx configuration is valid"
    else
        log_error "Nginx configuration test failed"
        exit 1
    fi
}

# Main SSL setup function
setup_ssl() {
    log_info "Starting SSL setup for WebP Safe Migrator with Nginx..."
    
    # Check if SSL is enabled via environment variable
    if [[ "${ENABLE_SSL:-true}" == "true" ]]; then
        generate_ssl_cert
        update_nginx_config
        setup_letsencrypt
        test_nginx_config
        
        # Configure WordPress SSL after a delay (WordPress might not be ready yet)
        (
            sleep 30
            configure_wordpress_ssl
        ) &
        
        log_success "SSL setup completed successfully"
        log_info "WordPress will be available at:"
        log_info "  HTTP:  http://${CUSTOM_DOMAIN:-localhost} (redirects to HTTPS)"
        log_info "  HTTPS: https://${CUSTOM_DOMAIN:-localhost}"
        log_info "  Admin: https://${CUSTOM_DOMAIN:-localhost}/wp-admin"
    else
        log_warning "SSL disabled via ENABLE_SSL=false"
        
        # Disable SSL in Nginx config
        sed -i 's/return 301 https:\/\/\$server_name\$request_uri;/try_files \$uri \$uri\/ \/index.php?\$args;/' /etc/nginx/sites-available/webp-migrator
        test_nginx_config
    fi
}

# Initialize WordPress if needed
init_wordpress() {
    log_info "Initializing WordPress..."
    
    # Run the original WordPress entrypoint to set up WordPress files
    if [[ -f "/usr/local/bin/wordpress-entrypoint.sh" ]]; then
        bash /usr/local/bin/wordpress-entrypoint.sh --help >/dev/null 2>&1 || true
        
        # Copy WordPress files if they don't exist
        if [[ ! -f "/var/www/html/index.php" ]]; then
            log_info "Copying WordPress files..."
            cp -r /usr/src/wordpress/* /var/www/html/
            chown -R www-data:www-data /var/www/html
        fi
    fi
    
    log_success "WordPress initialization completed"
}

# Create required directories
create_directories() {
    log_info "Creating required directories..."
    
    mkdir -p /var/log/supervisor /var/run
    chown www-data:www-data /var/log/webp-migrator
    
    log_success "Directories created"
}

# Main setup
log_info "Starting WebP Safe Migrator Nginx container setup..."

create_directories
init_wordpress
setup_ssl

log_success "Container setup completed, starting services..."

# Execute the command (supervisord)
exec "$@"
