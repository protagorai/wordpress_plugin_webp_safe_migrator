# WebP Safe Migrator - Bash Scripts Guide (Linux/macOS)

## Overview

This guide covers the bash scripts that provide the same functionality as the PowerShell scripts, designed for Linux and macOS development environments.

## Available Scripts

### 🚀 Quick Setup Scripts

#### `quick-install.sh` - One-Command Setup
Complete WordPress environment with zero configuration needed.

```bash
# Basic setup
./setup/quick-install.sh

# Custom installation path
./setup/quick-install.sh --install-path ~/my-webp-test

# Docker-based setup
./setup/quick-install.sh --use-docker

# Don't auto-start services
./setup/quick-install.sh --no-start
```

**What it does:**
- Installs complete LAMP/LEMP stack
- Downloads and configures WordPress
- Installs WebP Safe Migrator plugin
- Creates test content and images
- Opens browser to WordPress site

---

### 🔧 Detailed Setup Scripts

#### `install-wordpress.sh` - WordPress Environment Setup
Full control over WordPress installation with multiple options.

```bash
# Basic installation
./setup/install-wordpress.sh

# Custom configuration
./setup/install-wordpress.sh \
  --install-path ~/webp-test \
  --php-version 8.2 \
  --start-services

# Docker-based setup
./setup/install-wordpress.sh --use-docker

# Skip downloads if files exist
./setup/install-wordpress.sh --skip-downloads
```

**Features:**
- **Multi-OS Support**: Ubuntu, CentOS, Arch Linux, macOS
- **Package Manager Detection**: apt-get, yum, pacman, brew
- **Docker Alternative**: Use containers instead of native install
- **Service Management**: Automatic start/stop scripts
- **Web Server**: Apache with PHP support
- **Database**: MySQL/MariaDB with proper configuration

---

#### `install-wordpress-automated.sh` - Fully Automated Setup
Complete WordPress installation without manual intervention.

```bash
# Fully automated with defaults
./setup/install-wordpress-automated.sh

# Custom WordPress setup
./setup/install-wordpress-automated.sh \
  --site-title "My WebP Test Site" \
  --admin-user myuser \
  --admin-password mypass123 \
  --admin-email user@example.com

# No auto-install (just environment)
./setup/install-wordpress-automated.sh --no-auto-install
```

**Automated Features:**
- **WordPress Core Installation**: Via WP-CLI
- **Plugin Installation**: WebP Safe Migrator pre-installed and activated
- **Test Content**: Sample pages and instructions
- **Database Setup**: Complete with user and permissions
- **Test Images**: Sample images for conversion testing

---

### 🔌 Plugin Management

#### `plugin-manager.sh` - Complete Plugin Lifecycle
Enhanced plugin management with database operations and WP-CLI integration.

```bash
# Basic plugin operations
./setup/plugin-manager.sh install
./setup/plugin-manager.sh status
./setup/plugin-manager.sh uninstall

# Advanced operations with WP-CLI
./setup/plugin-manager.sh install --use-wpcli --setup-api
./setup/plugin-manager.sh activate --use-wpcli
./setup/plugin-manager.sh deactivate --use-wpcli

# Database operations
./setup/plugin-manager.sh setup-db
./setup/plugin-manager.sh cleanup
./setup/plugin-manager.sh backup

# Custom paths
./setup/plugin-manager.sh install \
  --wordpress-path ~/my-wp \
  --source-path ../plugin-src \
  --backup-path ./my-backups
```

**Plugin Manager Features:**
- **Complete Lifecycle**: install, update, activate, deactivate, uninstall
- **Database Integration**: Setup, cleanup, backup with validation
- **WP-CLI Support**: Optional WordPress API integration
- **Backup System**: Files + database backup and restore
- **Status Monitoring**: Comprehensive plugin and database status
- **API Setup**: REST endpoint configuration

---

## System Requirements

### Linux (Ubuntu/Debian)
```bash
# Install dependencies
sudo apt-get update
sudo apt-get install -y curl wget unzip git

# The scripts will install:
# - apache2, mysql-server, php8.1+
# - php extensions: mysql, gd, mbstring, xml, curl, zip
```

### Linux (CentOS/RHEL)
```bash
# Install dependencies
sudo yum update -y
sudo yum install -y curl wget unzip git

# The scripts will install:
# - httpd, mysql-server, php
# - php extensions: mysql, gd, mbstring, xml, curl, zip
```

### Linux (Arch)
```bash
# Install dependencies
sudo pacman -Syu
sudo pacman -S curl wget unzip git

# The scripts will install:
# - apache, mysql, php, php-apache
# - php extensions: gd, mysql
```

### macOS
```bash
# Install Homebrew first
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# The scripts will install:
# - httpd, mysql, php@8.1
# - All required extensions included
```

---

## Usage Examples

### 🎯 Quick Development Setup

```bash
# 1. One-command setup (recommended for quick testing)
./setup/quick-install.sh

# 2. Open browser to http://localhost:8080
# 3. WordPress is ready with plugin installed!
```

### 🔧 Custom Development Setup

```bash
# 1. Install WordPress environment
./setup/install-wordpress.sh --install-path ~/my-webp-dev

# 2. Install and activate plugin
./setup/plugin-manager.sh install \
  --wordpress-path ~/my-webp-dev/www \
  --use-wpcli

# 3. Start development
# Edit plugin files in src/
# Test changes: ./setup/plugin-manager.sh update --use-wpcli
```

### 🐳 Docker Development Setup

```bash
# 1. Docker-based environment (no system dependencies)
./setup/quick-install.sh --use-docker

# 2. Services available:
# - WordPress: http://localhost:8080
# - phpMyAdmin: http://localhost:8081

# 3. Plugin development:
# - Copy files to: ~/webp-migrator-test/plugins/webp-safe-migrator/
# - Activate in WordPress admin
```

### 🗄️ Database Management

```bash
# Setup plugin database
./setup/plugin-manager.sh setup-db

# Check status
./setup/plugin-manager.sh status

# Clean database
./setup/plugin-manager.sh cleanup

# Backup everything
./setup/plugin-manager.sh backup

# Restore from backup
./setup/plugin-manager.sh restore
```

---

## File Locations

### Default Installation Structure
```
~/webp-migrator-test/
├── www/                          # WordPress files
│   ├── wp-content/plugins/webp-safe-migrator/
│   └── wp-config.php
├── mysql/                        # MySQL data (if applicable)
├── logs/                         # Web server logs
├── scripts/                      # Service management
│   ├── start-services.sh
│   ├── stop-services.sh
│   └── install-plugin.sh
├── wp-cli.phar                   # WP-CLI tool
├── wp                           # WP-CLI wrapper
└── README.txt                   # Setup instructions
```

### Docker Structure
```
~/webp-migrator-test/
├── docker-compose.yml           # Container configuration
├── plugins/webp-safe-migrator/  # Plugin development directory
└── README.txt                   # Docker instructions
```

---

## Service Management

### Native Installation

```bash
# Start all services
~/webp-migrator-test/scripts/start-services.sh

# Stop all services
~/webp-migrator-test/scripts/stop-services.sh

# Install/update plugin
~/webp-migrator-test/scripts/install-plugin.sh
```

### Docker Installation

```bash
# Start containers
cd ~/webp-migrator-test
docker-compose up -d

# Stop containers
docker-compose down

# View logs
docker-compose logs -f
```

---

## Troubleshooting

### Common Issues

#### Permission Errors
```bash
# Fix file permissions
sudo chown -R $USER:$USER ~/webp-migrator-test
chmod +x setup/*.sh
```

#### Port Conflicts
```bash
# Check what's using ports
sudo netstat -tlnp | grep :8080
sudo netstat -tlnp | grep :3306

# Stop conflicting services
sudo systemctl stop apache2  # or httpd
sudo systemctl stop mysql    # or mysqld
```

#### Database Connection Issues
```bash
# Reset MySQL root password (Ubuntu)
sudo mysql
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'root123';
FLUSH PRIVILEGES;
EXIT;

# Test connection
mysql -u wordpress -pwordpress123 wordpress_webp_test
```

#### WP-CLI Issues
```bash
# Manual WP-CLI installation
cd ~/webp-migrator-test
curl -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x wp-cli.phar

# Test WP-CLI
cd www
php ../wp-cli.phar --info
```

### OS-Specific Issues

#### Ubuntu/Debian
```bash
# Enable Apache modules
sudo a2enmod rewrite
sudo a2enmod php8.1

# Restart Apache
sudo systemctl restart apache2
```

#### CentOS/RHEL
```bash
# Start and enable services
sudo systemctl start httpd mysql
sudo systemctl enable httpd mysql

# Configure SELinux (if needed)
sudo setsebool -P httpd_can_network_connect 1
```

#### macOS
```bash
# Start Homebrew services
brew services start httpd
brew services start mysql

# Fix PHP path
echo 'export PATH="/usr/local/opt/php@8.1/bin:$PATH"' >> ~/.zshrc
source ~/.zshrc
```

---

## Development Workflow

### Plugin Development Cycle

```bash
# 1. Initial setup
./setup/quick-install.sh

# 2. Make changes to plugin files in src/

# 3. Update plugin in WordPress
./setup/plugin-manager.sh update --use-wpcli

# 4. Test changes in browser at http://localhost:8080

# 5. Create backup before major changes
./setup/plugin-manager.sh backup

# 6. Repeat steps 2-5
```

### Testing Different Configurations

```bash
# Test with different PHP versions
./setup/install-wordpress.sh --php-version 8.0
./setup/install-wordpress.sh --php-version 8.2

# Test with Docker (isolated environment)
./setup/quick-install.sh --use-docker

# Test plugin lifecycle
./setup/plugin-manager.sh install --use-wpcli
./setup/plugin-manager.sh deactivate --use-wpcli
./setup/plugin-manager.sh activate --use-wpcli
./setup/plugin-manager.sh uninstall --use-wpcli
```

---

## Script Comparison with PowerShell

| Feature | PowerShell | Bash | Notes |
|---------|------------|------|-------|
| **OS Support** | Windows | Linux/macOS | Platform-specific implementations |
| **Package Managers** | Manual downloads | Native package managers | Better integration on Unix systems |
| **Service Management** | Windows services | systemd/launchd | OS-appropriate service management |
| **Database** | MariaDB portable | MySQL/MariaDB native | Better performance on native systems |
| **Web Server** | Nginx portable | Apache native | More stable on Unix systems |
| **Docker Support** | ✅ | ✅ | Cross-platform container support |
| **WP-CLI Integration** | ✅ | ✅ | Identical functionality |
| **Plugin Management** | ✅ | ✅ | Feature parity maintained |

The bash scripts provide identical functionality to the PowerShell scripts while leveraging native Unix tools and package managers for better integration and performance on Linux and macOS systems.
