# 🚀 WebP Safe Migrator - Command Cheat Sheet

Quick reference for the most frequently used commands across all platforms.

## 📋 Table of Contents
- [🚀 Launch](#-launch)
- [🛑 Shutdown](#-shutdown) 
- [🧹 Cleanup](#-cleanup)
- [📊 Status & Monitoring](#-status--monitoring)
- [🔧 Development](#-development)
- [⚙️ Configuration](#-configuration-management)
- [🆘 Emergency](#-emergency)

---

## 🚀 Launch

### **One-Command Setup (Recommended)**

#### Windows (PowerShell)
```powershell
# Complete automated setup - WordPress + Plugin ready in 2-3 minutes
.\setup\complete-auto-setup.ps1

# Simple deployment with basic options
.\setup\deploy-clean.ps1

# Quick development setup
.\setup\quick-install.ps1
```

#### Linux/macOS (Bash)
```bash
# Universal setup (auto-detects best method)
./setup/setup.sh

# One-command complete setup
./setup/quick-install.sh

# Instant deployment
./setup/instant-deploy.sh
```

### **Container Management**

#### Podman (Recommended - License Free)
```bash
# Start all services
./setup/podman-setup.sh up
./setup/podman-setup.ps1 up

# Install WordPress + Plugin
./setup/podman-setup.sh install
./setup/podman-setup.ps1 install
```

#### Docker (Commercial Licensing May Apply)
```bash
# Start all services
./setup/docker-setup.sh up

# Install WordPress + Plugin
./setup/docker-setup.sh install

# Using Docker Compose directly
docker-compose up -d
```

### **Access URLs After Launch**
| Service | URL | Credentials |
|---------|-----|-------------|
| **WordPress Site** | `http://localhost:8080` | N/A |
| **WordPress Admin** | `http://localhost:8080/wp-admin` | admin / admin123! |
| **Plugin Interface** | Media → WebP Migrator | Same as admin |
| **Database Admin** | `http://localhost:8081` | root / root123 |
| **Auto-Login URL** | `http://localhost:8080/?auto_login=dev_mode` | **← Use This** |

---

## 🛑 Shutdown

### **Graceful Shutdown (Recommended)**

#### Stop Containers (Preserves Data)
```bash
# Podman
podman stop webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin webp-migrator-wpcli

# Docker
docker-compose down
./setup/docker-setup.sh down

# Windows PowerShell
.\setup\podman-setup.ps1 down
```

#### Stop Native Services
```bash
# Linux/macOS
~/webp-migrator-test/scripts/stop-services.sh

# Individual services
sudo systemctl stop apache2 mysql  # Ubuntu/Debian
sudo systemctl stop httpd mysqld   # CentOS/RHEL
brew services stop httpd mysql     # macOS
```

### **Container Status Check Before Shutdown**
```bash
# Check what's running
podman ps
docker ps

# Check service health
./setup/podman-setup.sh status
./setup/docker-setup.sh status
```

---

## 🧹 Cleanup

### **Complete Environment Cleanup**

⚠️ **WARNING**: These commands remove ALL data including database, uploads, and WordPress files!

#### Container Cleanup
```bash
# Podman - Complete cleanup
./setup/podman-setup.sh clean

# Docker - Complete cleanup  
./setup/docker-setup.sh clean
docker-compose down -v --remove-orphans

# Windows PowerShell
.\setup\podman-setup.ps1 clean
```

#### Manual Container Cleanup
```bash
# Stop and remove all containers
podman stop webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin webp-migrator-wpcli
podman rm -f webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin webp-migrator-wpcli

# Remove volumes (⚠️ DELETES ALL DATA)
podman volume rm webp-migrator-wp-data webp-migrator-db-data

# Remove network
podman network rm webp-migrator-net

# System cleanup
podman system prune -f
docker system prune -f
```

### **Selective Cleanup**

#### Database Cleanup Only
```bash
# Clean plugin database entries only
./setup/plugin-manager.sh cleanup --with-database
.\setup\plugin-manager.ps1 cleanup -WithDatabase

# Manual database cleanup (if WP-CLI available)
wp option delete webp_safe_migrator_settings
wp option delete webp_migrator_queue
wp option delete webp_migrator_progress
```

#### Plugin Cleanup Only
```bash
# Remove plugin files but keep database
./setup/plugin-manager.sh uninstall
.\setup\plugin-manager.ps1 uninstall

# Remove plugin files and database
./setup/plugin-manager.sh uninstall --with-database
.\setup\plugin-manager.ps1 uninstall -WithDatabase
```

### **Native Installation Cleanup**
```bash
# Remove WordPress installation
rm -rf ~/webp-migrator-test/

# Stop and disable services
sudo systemctl stop apache2 mysql
sudo systemctl disable apache2 mysql

# Remove packages (if installed for this project)
sudo apt-get remove --purge apache2 mysql-server php*  # Ubuntu/Debian
```

---

## 📊 Status & Monitoring

### **Service Status**
```bash
# Container status
podman ps
docker ps

# Comprehensive status check
./setup/podman-setup.sh status
./setup/docker-setup.sh status
.\setup\podman-setup.ps1 status

# Plugin status
./setup/plugin-manager.sh status --with-database
.\setup\plugin-manager.ps1 status -WithDatabase
```

### **Log Monitoring**
```bash
# Follow container logs
./setup/docker-setup.sh logs --follow
./setup/podman-setup.sh logs --follow

# WordPress container logs
podman logs -f webp-migrator-wordpress
docker logs -f webp-migrator-wordpress

# Database container logs  
podman logs -f webp-migrator-mysql
docker logs -f webp-migrator-mysql
```

### **Health Checks**
```bash
# Check if services are responding
curl -I http://localhost:8080                    # WordPress
curl -I http://localhost:8081                    # phpMyAdmin
mysql -h 127.0.0.1 -P 3307 -u wordpress -pwordpress123  # Database

# Network connectivity
podman exec webp-migrator-wordpress ping -c 1 webp-migrator-mysql
```

---

## 🔧 Development

### **Plugin Development**
```bash
# Update plugin after code changes
./setup/plugin-manager.sh update --use-wpcli
.\setup\plugin-manager.ps1 update -UseWPCLI

# Create backup before changes
./setup/plugin-manager.sh backup
.\setup\plugin-manager.ps1 backup

# Restore from backup
./setup/plugin-manager.sh restore
.\setup\plugin-manager.ps1 restore
```

### **WordPress Management via WP-CLI**
```bash
# Execute WP-CLI commands
./setup/docker-setup.sh wp plugin list
./setup/podman-setup.sh wp plugin list

# Direct container access
podman exec -it webp-migrator-wordpress wp plugin list --allow-root
docker exec -it webp-migrator-wordpress wp plugin list --allow-root

# WordPress updates
podman exec webp-migrator-wordpress wp core update --allow-root
```

### **Database Operations**
```bash
# Open MySQL shell
./setup/docker-setup.sh mysql
./setup/podman-setup.sh mysql

# Direct database access
mysql -h 127.0.0.1 -P 3307 -u wordpress -pwordpress123 wordpress_webp_test

# Database backup
podman exec webp-migrator-mysql mysqldump -u root -proot123 wordpress_webp_test > backup.sql

# Database restore
cat backup.sql | podman exec -i webp-migrator-mysql mysql -u root -proot123 wordpress_webp_test
```

### **Container Shell Access**
```bash
# WordPress container shell
./setup/docker-setup.sh shell
./setup/podman-setup.sh shell

# Direct container access
podman exec -it webp-migrator-wordpress bash
docker exec -it webp-migrator-wordpress bash

# Database container shell
podman exec -it webp-migrator-mysql bash
```

---

## ⚙️ Configuration Management

### **Configuration Templates**

#### Copy and Customize Templates
```bash
# Simple configuration (recommended for beginners)
cp setup/simple-config.yaml my-config.yaml

# Complete configuration (all options)  
cp setup/webp-migrator-config.yaml my-config.yaml

# Edit configuration file
nano my-config.yaml        # Linux/macOS
notepad my-config.yaml     # Windows
```

#### Key Settings to Customize
```yaml
# Database credentials
database:
  name: "my_wordpress_db"           # Custom database name
  wordpress_user:
    username: "my_wp_user"          # Custom WordPress DB user
    password: "auto"                # Auto-generate secure password

# WordPress admin
wordpress:
  site:
    title: "My WebP Site"           # Site title
    url: "https://my-domain.com"    # Custom domain
  admin_user:
    username: "myadmin"             # Admin username  
    password: "auto"                # Auto-generate secure password
    email: "admin@my-domain.com"    # Admin email

# Infrastructure
infrastructure:
  container_engine: "podman"        # or "docker"
  install_path: "~/my-webp-test"    # Installation directory
  ports:
    http: 8080                      # Custom HTTP port
    https: 8443                     # Custom HTTPS port
```

### **Generate Custom Setup**

#### Configuration-Based Deployment
```bash
# Generate all setup files from configuration
./setup/generate-config.sh my-config.yaml

# Deploy with generated configuration
cd setup/generated/
docker-compose up -d
./install-automated.sh

# Or use Python directly  
python3 setup/config-generator.py my-config.yaml -o setup/generated/
```

#### Configuration Examples
```bash
# Development setup
cp setup/simple-config.yaml dev-config.yaml
# Edit dev-config.yaml for development settings
./setup/generate-config.sh dev-config.yaml -o dev/

# Production setup
cp setup/webp-migrator-config.yaml prod-config.yaml  
# Edit prod-config.yaml for production settings
./setup/generate-config.sh prod-config.yaml -o production/
```

### **Configuration Validation**
```bash
# Check YAML syntax
python3 -c "import yaml; yaml.safe_load(open('my-config.yaml'))"

# Validate Docker Compose output
cd setup/generated/
docker-compose config

# Test configuration without deploying
./setup/generate-config.sh my-config.yaml --validate-only
```

---

## 🆘 Emergency

### **Force Stop Everything**
```bash
# Emergency container stop
podman kill webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin webp-migrator-wpcli
docker kill webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin webp-migrator-wpcli

# Kill all WebP Migrator containers
podman kill $(podman ps -q --filter name=webp-migrator)
docker kill $(docker ps -q --filter name=webp-migrator)
```

### **Port Conflict Resolution**
```bash
# Find what's using ports
sudo netstat -tulpn | grep :8080
sudo netstat -tulpn | grep :3307
lsof -i :8080  # macOS

# Kill processes using ports
sudo kill $(sudo lsof -t -i:8080)
sudo kill $(sudo lsof -t -i:3307)
```

### **Clean Restart After Problems**
```bash
# Complete clean restart
./setup/podman-setup.sh clean
./setup/podman-setup.sh up
./setup/podman-setup.sh install

# Or with force cleanup
podman system prune -f
podman volume prune -f
./setup/podman-setup.sh up
```

### **Recovery from Failed Cleanup**
```bash
# Manual cleanup if scripts fail
podman rm -f $(podman ps -aq --filter name=webp-migrator) 2>/dev/null || true
podman volume rm $(podman volume ls -q --filter name=webp-migrator) 2>/dev/null || true
podman network rm webp-migrator-net 2>/dev/null || true

# Remove local files
rm -rf ~/webp-migrator-test/
```

---

## ⚡ Platform-Specific Quick Commands

### Windows PowerShell
```powershell
# All-in-one setup
.\setup\complete-auto-setup.ps1

# Management
.\setup\podman-setup.ps1 up
.\setup\podman-setup.ps1 status  
.\setup\podman-setup.ps1 clean
```

### Linux/macOS
```bash
# All-in-one setup
./setup/setup.sh

# Management  
./setup/podman-setup.sh up
./setup/podman-setup.sh status
./setup/podman-setup.sh clean
```

### Docker Compose
```bash
# Setup and management
cd setup/
docker-compose up -d
docker-compose ps
docker-compose logs -f
docker-compose down -v
```

---

## 📚 Related Documentation

- **[🚀 Quick Start Guide](QUICK_START.md)** - Complete setup walkthrough
- **[📖 Deployment Guide](DEPLOYMENT_GUIDE.md)** - Detailed deployment options
- **[🛑 Graceful Shutdown Guide](GRACEFUL_SHUTDOWN.md)** - Proper shutdown procedures
- **[🐧 Bash Scripts Guide](BASH_SCRIPTS_GUIDE.md)** - Linux/macOS specific documentation
- **[🪟 Plugin Manager Guide](PLUGIN_MANAGER_GUIDE.md)** - Plugin lifecycle management

---

## 🎯 Most Common Workflows

### **Daily Development**
```bash
1. ./setup/podman-setup.sh up          # Start environment
2. # Edit files in src/
3. ./setup/plugin-manager.sh update    # Update plugin
4. # Test in browser at http://localhost:8080
5. ./setup/podman-setup.sh down        # Stop when done
```

### **Fresh Start**
```bash
1. ./setup/podman-setup.sh clean       # Clean everything
2. ./setup/complete-auto-setup.ps1     # Fresh setup
3. # WordPress ready at http://localhost:8080/?auto_login=dev_mode
```

### **Quick Plugin Test**
```bash
1. ./setup/quick-install.sh            # Quick setup
2. # Browser opens automatically to WordPress with plugin activated
3. # Go to Media → WebP Migrator and start testing
```

---

**💡 Pro Tips:**
- Use auto-login URL: `http://localhost:8080/?auto_login=dev_mode` to skip manual login
- Always check status before shutdown: `./setup/podman-setup.sh status`
- Create backups before major changes: `./setup/plugin-manager.sh backup`
- Use Podman instead of Docker to avoid licensing restrictions
- Keep containers running between development sessions - just stop/start as needed

**⚠️ Safety Reminders:**
- `clean` commands delete ALL data - backup first if needed
- `down` vs `clean`: `down` stops containers, `clean` removes everything
- Always verify what's running with `podman ps` or `docker ps`
- Use `--clean-start` option if containers already exist
