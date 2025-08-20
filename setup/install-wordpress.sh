#!/bin/bash
# WordPress Development Environment Setup Script for Linux/macOS
# This script installs Apache/Nginx, MySQL, PHP, and WordPress for testing the WebP Safe Migrator plugin

set -e  # Exit on any error

# Default configuration
INSTALL_PATH="${1:-$HOME/webp-migrator-test}"
WP_VERSION="${2:-latest}"
PHP_VERSION="${3:-8.1}"
SKIP_DOWNLOADS=false
START_SERVICES=false
USE_DOCKER=false

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --install-path)
            INSTALL_PATH="$2"
            shift 2
            ;;
        --wp-version)
            WP_VERSION="$2"
            shift 2
            ;;
        --php-version)
            PHP_VERSION="$2"
            shift 2
            ;;
        --skip-downloads)
            SKIP_DOWNLOADS=true
            shift
            ;;
        --start-services)
            START_SERVICES=true
            shift
            ;;
        --use-docker)
            USE_DOCKER=true
            shift
            ;;
        --help)
            echo "Usage: $0 [OPTIONS]"
            echo "Options:"
            echo "  --install-path PATH    Installation directory (default: ~/webp-migrator-test)"
            echo "  --wp-version VERSION   WordPress version (default: latest)"
            echo "  --php-version VERSION  PHP version (default: 8.1)"
            echo "  --skip-downloads       Skip downloading if files exist"
            echo "  --start-services       Start services after installation"
            echo "  --use-docker          Use Docker containers instead of native install"
            echo "  --help                Show this help message"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${CYAN}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Detect OS
detect_os() {
    if [[ "$OSTYPE" == "linux-gnu"* ]]; then
        if command -v apt-get >/dev/null 2>&1; then
            OS="ubuntu"
            PACKAGE_MANAGER="apt-get"
        elif command -v yum >/dev/null 2>&1; then
            OS="centos"
            PACKAGE_MANAGER="yum"
        elif command -v pacman >/dev/null 2>&1; then
            OS="arch"
            PACKAGE_MANAGER="pacman"
        else
            OS="linux"
            PACKAGE_MANAGER="unknown"
        fi
    elif [[ "$OSTYPE" == "darwin"* ]]; then
        OS="macos"
        PACKAGE_MANAGER="brew"
        if ! command -v brew >/dev/null 2>&1; then
            log_error "Homebrew not found. Please install Homebrew first: https://brew.sh/"
            exit 1
        fi
    else
        log_error "Unsupported operating system: $OSTYPE"
        exit 1
    fi
    
    log_info "Detected OS: $OS with package manager: $PACKAGE_MANAGER"
}

# Check if running as root (needed for some operations)
check_permissions() {
    if [[ $EUID -eq 0 ]] && [[ "$OS" != "macos" ]]; then
        log_warning "Running as root. This is not recommended for development environments."
        read -p "Continue anyway? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    fi
}

# Install dependencies based on OS
install_dependencies() {
    log_info "Installing system dependencies..."
    
    case $OS in
        "ubuntu")
            sudo apt-get update
            sudo apt-get install -y \
                apache2 \
                mysql-server \
                php${PHP_VERSION} \
                php${PHP_VERSION}-mysql \
                php${PHP_VERSION}-gd \
                php${PHP_VERSION}-mbstring \
                php${PHP_VERSION}-xml \
                php${PHP_VERSION}-curl \
                php${PHP_VERSION}-zip \
                libapache2-mod-php${PHP_VERSION} \
                wget \
                unzip \
                curl
            ;;
        "centos")
            sudo yum update -y
            sudo yum install -y \
                httpd \
                mysql-server \
                php \
                php-mysql \
                php-gd \
                php-mbstring \
                php-xml \
                php-curl \
                php-zip \
                wget \
                unzip \
                curl
            ;;
        "arch")
            sudo pacman -Syu --noconfirm
            sudo pacman -S --noconfirm \
                apache \
                mysql \
                php \
                php-apache \
                php-gd \
                php-mysql \
                wget \
                unzip \
                curl
            ;;
        "macos")
            # Install via Homebrew
            brew update
            brew install \
                httpd \
                mysql \
                php@${PHP_VERSION} \
                wget \
                curl
            
            # Link PHP version
            brew link php@${PHP_VERSION} --force
            ;;
    esac
    
    log_success "Dependencies installed successfully"
}

# Setup Docker environment (alternative to native install)
setup_docker() {
    if ! command -v docker >/dev/null 2>&1; then
        log_error "Docker not found. Please install Docker first."
        exit 1
    fi
    
    if ! command -v docker-compose >/dev/null 2>&1; then
        log_error "Docker Compose not found. Please install Docker Compose first."
        exit 1
    fi
    
    log_info "Setting up Docker environment..."
    
    mkdir -p "$INSTALL_PATH"
    cd "$INSTALL_PATH"
    
    # Create docker-compose.yml
    cat > docker-compose.yml << EOF
version: '3.8'

services:
  wordpress:
    image: wordpress:${WP_VERSION}
    ports:
      - "8080:80"
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: wordpress123
      WORDPRESS_DB_NAME: wordpress_webp_test
      WORDPRESS_DEBUG: 1
    volumes:
      - wordpress_data:/var/www/html
      - ./plugins:/var/www/html/wp-content/plugins
    depends_on:
      - db

  db:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: wordpress_webp_test
      MYSQL_USER: wordpress
      MYSQL_PASSWORD: wordpress123
      MYSQL_ROOT_PASSWORD: root123
    volumes:
      - db_data:/var/lib/mysql
    ports:
      - "3306:3306"

  phpmyadmin:
    image: phpmyadmin:latest
    ports:
      - "8081:80"
    environment:
      PMA_HOST: db
      PMA_USER: root
      PMA_PASSWORD: root123
    depends_on:
      - db

volumes:
  wordpress_data:
  db_data:
EOF

    # Create plugin directory
    mkdir -p plugins/webp-safe-migrator
    
    log_success "Docker environment configured"
    
    if [[ "$START_SERVICES" == true ]]; then
        log_info "Starting Docker containers..."
        docker-compose up -d
        log_success "Docker containers started!"
        log_info "WordPress: http://localhost:8080"
        log_info "phpMyAdmin: http://localhost:8081"
    fi
    
    return 0
}

# Create directory structure
create_directories() {
    log_info "Creating directory structure..."
    
    mkdir -p "$INSTALL_PATH"/{www,mysql,logs,scripts}
    
    # Set proper permissions
    if [[ "$OS" != "macos" ]]; then
        sudo chown -R $USER:$USER "$INSTALL_PATH"
    fi
    
    log_success "Directory structure created"
}

# Download and setup WordPress
setup_wordpress() {
    log_info "Setting up WordPress..."
    
    cd "$INSTALL_PATH"
    
    # Create temporary directory for downloads
    mkdir -p temp
    
    if [[ "$SKIP_DOWNLOADS" == false ]] || [[ ! -f "temp/wordpress.zip" ]]; then
        log_info "Downloading WordPress latest version..."
        
        # Use the official WordPress download URL
        WP_DOWNLOAD_URL="https://wordpress.org/latest.zip"
        
        if command -v wget >/dev/null 2>&1; then
            wget -O temp/wordpress.zip "$WP_DOWNLOAD_URL"
        elif command -v curl >/dev/null 2>&1; then
            curl -L -o temp/wordpress.zip "$WP_DOWNLOAD_URL"
        else
            log_error "Neither wget nor curl available for downloading"
            exit 1
        fi
        
        # Verify download
        if [[ ! -f "temp/wordpress.zip" ]] || [[ ! -s "temp/wordpress.zip" ]]; then
            log_error "WordPress download failed or file is empty"
            exit 1
        fi
        
        log_success "WordPress downloaded successfully"
    else
        log_info "Using existing WordPress download"
    fi
    
    # Prepare www directory
    if [[ -d "www" ]]; then
        log_info "Removing existing WordPress files..."
        rm -rf www/*
    else
        mkdir -p www
    fi
    
    # Extract WordPress
    log_info "Extracting WordPress archive..."
    
    # Create extraction directory
    mkdir -p temp/extract
    
    if command -v unzip >/dev/null 2>&1; then
        unzip -q temp/wordpress.zip -d temp/extract/
    else
        log_error "unzip command not available"
        exit 1
    fi
    
    # Move WordPress files to www directory
    if [[ -d "temp/extract/wordpress" ]]; then
        mv temp/extract/wordpress/* www/
        log_success "WordPress files extracted to www/"
    else
        log_error "WordPress extraction failed - wordpress directory not found"
        exit 1
    fi
    
    # Cleanup temporary files
    log_info "Cleaning up temporary files..."
    rm -rf temp/extract
    
    # Cleanup downloaded archive to save space
    if [[ "$SKIP_DOWNLOADS" == false ]]; then
        log_info "Cleaning up WordPress download archive..."
        rm -f temp/wordpress.zip
        log_success "Download cleanup completed"
    else
        log_info "Keeping WordPress download for future use"
    fi
    
    # Create wp-config.php
    cd www
    if [[ -f "wp-config-sample.php" ]] && [[ ! -f "wp-config.php" ]]; then
        cp wp-config-sample.php wp-config.php
        
        # Replace database settings
        sed -i.bak "s/database_name_here/wordpress_webp_test/g" wp-config.php
        sed -i.bak "s/username_here/wordpress/g" wp-config.php
        sed -i.bak "s/password_here/wordpress123/g" wp-config.php
        sed -i.bak "s/localhost/127.0.0.1/g" wp-config.php
        
        # Add debug settings
        cat >> wp-config.php << 'EOF'

/* Debug settings for development */
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('SCRIPT_DEBUG', true);

/* Increase memory limit */
define('WP_MEMORY_LIMIT', '512M');

/* Allow direct file modifications */
define('FS_METHOD', 'direct');
EOF
        
        rm wp-config.php.bak
    fi
    
    log_success "WordPress configured"
}

# Configure web server
configure_webserver() {
    log_info "Configuring web server..."
    
    case $OS in
        "ubuntu"|"centos")
            # Apache configuration
            APACHE_CONF="/etc/apache2/sites-available/webp-migrator.conf"
            if [[ "$OS" == "centos" ]]; then
                APACHE_CONF="/etc/httpd/conf.d/webp-migrator.conf"
            fi
            
            sudo tee "$APACHE_CONF" > /dev/null << EOF
<VirtualHost *:8080>
    DocumentRoot $INSTALL_PATH/www
    ServerName localhost
    
    <Directory $INSTALL_PATH/www>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog $INSTALL_PATH/logs/error.log
    CustomLog $INSTALL_PATH/logs/access.log combined
</VirtualHost>
EOF
            
            # Enable site and modules
            if [[ "$OS" == "ubuntu" ]]; then
                sudo a2ensite webp-migrator
                sudo a2enmod rewrite
                sudo a2enmod php${PHP_VERSION}
                
                # Change Apache port
                sudo sed -i 's/Listen 80/Listen 8080/g' /etc/apache2/ports.conf
            else
                # CentOS
                sudo sed -i 's/Listen 80/Listen 8080/g' /etc/httpd/conf/httpd.conf
            fi
            ;;
        "macos")
            # Use Homebrew's Apache
            APACHE_CONF="/usr/local/etc/httpd/other/webp-migrator.conf"
            
            cat > "$APACHE_CONF" << EOF
Listen 8080
<VirtualHost *:8080>
    DocumentRoot $INSTALL_PATH/www
    ServerName localhost
    
    <Directory $INSTALL_PATH/www>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog $INSTALL_PATH/logs/error.log
    CustomLog $INSTALL_PATH/logs/access.log combined
</VirtualHost>
EOF
            ;;
    esac
    
    log_success "Web server configured"
}

# Setup MySQL database
setup_mysql() {
    log_info "Setting up MySQL database..."
    
    case $OS in
        "ubuntu"|"centos")
            sudo systemctl start mysql || sudo systemctl start mysqld
            sudo systemctl enable mysql || sudo systemctl enable mysqld
            ;;
        "macos")
            brew services start mysql
            ;;
    esac
    
    # Wait for MySQL to start
    sleep 5
    
    # Create database and user
    mysql -u root -e "CREATE DATABASE IF NOT EXISTS wordpress_webp_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null || {
        log_warning "Setting MySQL root password..."
        sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'root123';"
        mysql -u root -proot123 -e "CREATE DATABASE IF NOT EXISTS wordpress_webp_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    }
    
    mysql -u root -proot123 -e "CREATE USER IF NOT EXISTS 'wordpress'@'localhost' IDENTIFIED BY 'wordpress123';" 2>/dev/null || true
    mysql -u root -proot123 -e "GRANT ALL PRIVILEGES ON wordpress_webp_test.* TO 'wordpress'@'localhost';"
    mysql -u root -proot123 -e "FLUSH PRIVILEGES;"
    
    log_success "MySQL database configured"
}

# Create service management scripts
create_scripts() {
    log_info "Creating service management scripts..."
    
    # Start services script
    cat > "$INSTALL_PATH/scripts/start-services.sh" << EOF
#!/bin/bash
echo "Starting WebP Migrator Test Environment..."

# Start MySQL
case "$OS" in
    "ubuntu"|"centos")
        sudo systemctl start mysql || sudo systemctl start mysqld
        ;;
    "macos")
        brew services start mysql
        ;;
esac

# Start Apache
case "$OS" in
    "ubuntu")
        sudo systemctl start apache2
        ;;
    "centos")
        sudo systemctl start httpd
        ;;
    "macos")
        sudo /usr/local/bin/httpd -D FOREGROUND &
        ;;
esac

echo ""
echo "Services started!"
echo "WordPress is available at: http://localhost:8080"
echo ""
EOF

    # Stop services script
    cat > "$INSTALL_PATH/scripts/stop-services.sh" << EOF
#!/bin/bash
echo "Stopping WebP Migrator Test Environment..."

# Stop Apache
case "$OS" in
    "ubuntu")
        sudo systemctl stop apache2
        ;;
    "centos")
        sudo systemctl stop httpd
        ;;
    "macos")
        sudo pkill httpd
        ;;
esac

# Stop MySQL
case "$OS" in
    "ubuntu"|"centos")
        sudo systemctl stop mysql || sudo systemctl stop mysqld
        ;;
    "macos")
        brew services stop mysql
        ;;
esac

echo ""
echo "Services stopped!"
echo ""
EOF

    # Plugin installation script
    cat > "$INSTALL_PATH/scripts/install-plugin.sh" << EOF
#!/bin/bash
echo "Installing WebP Safe Migrator Plugin..."

PLUGIN_DIR="$INSTALL_PATH/www/wp-content/plugins/webp-safe-migrator"
SOURCE_DIR="\$(dirname "\$(dirname "\$0")")/src"

if [[ -d "\$PLUGIN_DIR" ]]; then
    echo "Removing existing plugin..."
    rm -rf "\$PLUGIN_DIR"
fi

echo "Creating plugin directory..."
mkdir -p "\$PLUGIN_DIR"

echo "Copying plugin files..."
cp -r "\$SOURCE_DIR/"* "\$PLUGIN_DIR/"

echo "Plugin installed successfully!"
echo "You can now activate it in WordPress admin."
EOF

    # Make scripts executable
    chmod +x "$INSTALL_PATH/scripts/"*.sh
    
    log_success "Service management scripts created"
}

# Create README
create_readme() {
    cat > "$INSTALL_PATH/README.txt" << EOF
# WebP Safe Migrator Test Environment

This is a local WordPress development environment for testing the WebP Safe Migrator plugin.

## Quick Start

1. Run './scripts/start-services.sh' to start all services
2. Open http://localhost:8080 in your browser
3. Complete WordPress installation
4. Run './scripts/install-plugin.sh' to install the WebP Safe Migrator plugin
5. Activate the plugin in WordPress admin

## Service Management

- **Start Services**: ./scripts/start-services.sh
- **Stop Services**: ./scripts/stop-services.sh
- **Install Plugin**: ./scripts/install-plugin.sh

## Access Information

- **WordPress URL**: http://localhost:8080
- **MySQL Host**: 127.0.0.1:3306
- **MySQL Database**: wordpress_webp_test
- **MySQL Username**: wordpress
- **MySQL Password**: wordpress123
- **MySQL Root Password**: root123

## Directory Structure

- **Web Root**: $INSTALL_PATH/www
- **MySQL Data**: System default location
- **Logs**: $INSTALL_PATH/logs
- **Scripts**: $INSTALL_PATH/scripts

## Troubleshooting

1. Check if ports 8080 and 3306 are available
2. Ensure all services are running: ./scripts/start-services.sh
3. Check logs in $INSTALL_PATH/logs/
4. For permission issues, ensure proper ownership of files

## Plugin Development

The plugin source is in the '../src' directory. After making changes:
1. Run './scripts/install-plugin.sh' to update the plugin
2. Refresh WordPress admin to see changes

EOF

    log_success "README created"
}

# Main execution
main() {
    echo -e "${GREEN}=== WebP Migrator WordPress Test Environment Setup ===${NC}"
    echo "Installation path: $INSTALL_PATH"
    echo ""
    
    detect_os
    check_permissions
    
    if [[ "$USE_DOCKER" == true ]]; then
        setup_docker
        exit 0
    fi
    
    install_dependencies
    create_directories
    setup_wordpress
    configure_webserver
    setup_mysql
    create_scripts
    create_readme
    
    if [[ "$START_SERVICES" == true ]]; then
        log_info "Starting services..."
        bash "$INSTALL_PATH/scripts/start-services.sh"
    fi
    
    echo ""
    echo -e "${GREEN}=== Setup Complete! ===${NC}"
    echo "Installation path: $INSTALL_PATH"
    echo ""
    echo -e "${CYAN}Next steps:${NC}"
    echo "1. Run './scripts/start-services.sh' to start services"
    echo "2. Open http://localhost:8080 to set up WordPress"
    echo "3. Run './scripts/install-plugin.sh' to install WebP Safe Migrator"
    echo ""
    echo "See README.txt for detailed instructions."
}

# Run main function
main "$@"
