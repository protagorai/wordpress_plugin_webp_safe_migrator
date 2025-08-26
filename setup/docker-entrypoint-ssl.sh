#!/bin/bash
# WebP Safe Migrator - Docker Entrypoint with SSL Support
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

log_info() {
    echo -e "${CYAN}[SSL-SETUP]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SSL-SETUP]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[SSL-SETUP]${NC} $1"
}

log_error() {
    echo -e "${RED}[SSL-SETUP]${NC} $1"
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
            "/C=US/ST=Development/L=Local/O=WebP Migrator/OU=Development/CN=localhost/emailAddress=dev@webp-migrator.local" \
            -addext "subjectAltName=DNS:localhost,DNS:webp-migrator.local,DNS:*.webp-migrator.local,IP:127.0.0.1"
        
        # Set proper permissions
        chmod 600 "$key_file"
        chmod 644 "$cert_file"
        chown root:root "$cert_file" "$key_file"
        
        log_success "SSL certificate generated successfully"
    else
        log_info "SSL certificate already exists"
    fi
}

# Setup SSL virtual host
setup_ssl_vhost() {
    log_info "Configuring SSL virtual host..."
    
    # Enable SSL site
    a2ensite webp-migrator-ssl
    
    # Disable default sites to avoid conflicts
    a2dissite 000-default default-ssl 2>/dev/null || true
    
    log_success "SSL virtual host configured"
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
" "$wp_config"
            
            log_success "WordPress SSL configuration added"
        else
            log_info "WordPress SSL configuration already present"
        fi
    else
        log_warning "wp-config.php not found, SSL configuration will be added later"
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

# Main SSL setup function
setup_ssl() {
    log_info "Starting SSL setup for WebP Safe Migrator..."
    
    # Check if SSL is enabled via environment variable
    if [[ "${ENABLE_SSL:-true}" == "true" ]]; then
        generate_ssl_cert
        setup_ssl_vhost
        setup_letsencrypt
        
        # Configure WordPress SSL after a delay (WordPress might not be ready yet)
        (
            sleep 30
            configure_wordpress_ssl
        ) &
        
        log_success "SSL setup completed successfully"
        log_info "WordPress will be available at:"
        log_info "  HTTP:  http://localhost (redirects to HTTPS)"
        log_info "  HTTPS: https://localhost"
        log_info "  Admin: https://localhost/wp-admin"
    else
        log_warning "SSL disabled via ENABLE_SSL=false"
    fi
}

# Run SSL setup
setup_ssl

# Execute the original WordPress entrypoint
log_info "Starting WordPress with SSL support..."
exec docker-entrypoint.sh "$@"
