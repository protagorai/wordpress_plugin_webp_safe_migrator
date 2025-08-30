# 🛑 WebP Safe Migrator - Graceful Shutdown Guide

Step-by-step procedures for properly shutting down your WordPress development environment without data loss.

## 📋 Table of Contents
- [🎯 Shutdown Overview](#-shutdown-overview)
- [📋 Pre-Shutdown Checklist](#-pre-shutdown-checklist)
- [🐳 Container Environment Shutdown](#-container-environment-shutdown)
- [🖥️ Native Environment Shutdown](#️-native-environment-shutdown)
- [🧹 Post-Shutdown Cleanup](#-post-shutdown-cleanup)
- [🆘 Emergency Shutdown](#-emergency-shutdown)
- [🔄 Restart Procedures](#-restart-procedures)

---

## 🎯 Shutdown Overview

### **Types of Shutdown**

| Shutdown Type | Data Preserved | Restart Speed | When to Use |
|---------------|----------------|---------------|-------------|
| **Graceful Stop** | ✅ All data | Fast | Daily development |
| **Clean Shutdown** | ✅ All data | Medium | End of project session |
| **Complete Cleanup** | ❌ All deleted | Slow (full setup needed) | Fresh start needed |
| **Emergency Stop** | ⚠️ May lose unsaved | Fast | System problems |

### **Recommended Approach**
For **daily development**: Use **Graceful Stop** to preserve all data and enable fast restarts.
For **project completion**: Use **Complete Cleanup** to remove all project files and containers.

---

## 📋 Pre-Shutdown Checklist

### **1. Check Current Activity**
```bash
# Check if any WordPress operations are running
./setup/podman-setup.sh status
./setup/docker-setup.sh status

# View recent activity logs
podman logs --tail 20 webp-migrator-wordpress
docker logs --tail 20 webp-migrator-wordpress
```

### **2. Verify Plugin State**
```bash
# Check plugin processing status
./setup/plugin-manager.sh status --with-database

# View plugin logs if processing was active
tail -f ~/webp-migrator-test/www/wp-content/debug.log  # Native install
```

### **3. Check for Active Connections**
```bash
# Check database connections
podman exec webp-migrator-mysql mysqladmin processlist -u root -proot123

# Check web server connections  
curl -I http://localhost:8080
curl -I http://localhost:8081
```

### **4. Backup Critical Data (If Needed)**
```bash
# Quick plugin backup
./setup/plugin-manager.sh backup

# Database backup
podman exec webp-migrator-mysql mysqldump -u root -proot123 wordpress_webp_test > backup-$(date +%Y%m%d).sql

# WordPress files backup (if customized)
tar -czf wordpress-backup-$(date +%Y%m%d).tar.gz -C ~/webp-migrator-test www/
```

---

## 🐳 Container Environment Shutdown

### **Step 1: Graceful Container Shutdown**

#### Option A: Using Setup Scripts (Recommended)
```bash
# Podman environment
./setup/podman-setup.sh down

# Docker environment  
./setup/docker-setup.sh down

# Windows PowerShell
.\setup\podman-setup.ps1 down
```

#### Option B: Manual Container Shutdown
```bash
# Step-by-step graceful shutdown

# 1. Stop WordPress container first (stops web traffic)
podman stop webp-migrator-wordpress

# 2. Stop WP-CLI container (stops any CLI operations)
podman stop webp-migrator-wpcli

# 3. Stop phpMyAdmin (stops database admin interface)
podman stop webp-migrator-phpmyadmin

# 4. Stop MySQL last (ensures no data corruption)
podman stop webp-migrator-mysql
```

#### Option C: Docker Compose Shutdown
```bash
# If using docker-compose directly
cd setup/
docker-compose stop     # Graceful stop
docker-compose down     # Stop and remove containers (keeps volumes)
```

### **Step 2: Verify Shutdown**
```bash
# Verify all containers are stopped
podman ps | grep webp-migrator
docker ps | grep webp-migrator

# Should return no running containers
```

### **Step 3: Network Cleanup (Optional)**
```bash
# Remove project network (containers can be restarted without it)
podman network rm webp-migrator-net 2>/dev/null || true
docker network rm setup_webp-migrator-net 2>/dev/null || true
```

### **Step 4: Port Release Verification**
```bash
# Verify ports are released
netstat -tulpn | grep :8080
netstat -tulpn | grep :3307
netstat -tulpn | grep :8081

# Should return no results
```

---

## 🖥️ Native Environment Shutdown

### **Step 1: Stop WordPress Processing**
```bash
# If any plugin processing is active, wait for completion or cancel
# Check WordPress admin: Media → WebP Migrator
```

### **Step 2: Stop Web Services**

#### Ubuntu/Debian
```bash
# Stop Apache web server
sudo systemctl stop apache2

# Stop MySQL database  
sudo systemctl stop mysql

# Verify services stopped
sudo systemctl status apache2
sudo systemctl status mysql
```

#### CentOS/RHEL
```bash
# Stop HTTPD web server
sudo systemctl stop httpd

# Stop MySQL database
sudo systemctl stop mysqld
# or MariaDB
sudo systemctl stop mariadb

# Verify services stopped
sudo systemctl status httpd mysqld
```

#### macOS (Homebrew)
```bash
# Stop Apache web server
brew services stop httpd

# Stop MySQL database
brew services stop mysql

# Verify services stopped
brew services list | grep -E "(httpd|mysql)"
```

### **Step 3: Process Cleanup**
```bash
# Check for any remaining processes
ps aux | grep -E "(apache|httpd|mysql)"

# Kill any hung processes (if necessary)
sudo pkill -f apache2
sudo pkill -f mysqld
```

### **Step 4: Verify Port Release**
```bash
# Check that ports are released
sudo netstat -tulpn | grep :80
sudo netstat -tulpn | grep :3306

# Should return no results
```

---

## 🧹 Post-Shutdown Cleanup

### **Temporary Files Cleanup**
```bash
# Clean temporary files
rm -f /tmp/webp-migrator-*
rm -f /tmp/wp-cli-*

# Clean log files (optional)
truncate -s 0 ~/webp-migrator-test/logs/*.log 2>/dev/null || true
```

### **System Resource Cleanup**
```bash
# Clear Docker/Podman system cache (optional)
podman system prune -f
docker system prune -f

# Clear unused volumes (⚠️ only if you want to free space)
podman volume prune -f
docker volume prune -f
```

### **Development Environment Cleanup**
```bash
# Clear WordPress cache files
rm -rf ~/webp-migrator-test/www/wp-content/cache/* 2>/dev/null || true
rm -rf ~/webp-migrator-test/www/wp-content/uploads/cache/* 2>/dev/null || true

# Clear plugin temporary files
rm -rf ~/webp-migrator-test/www/wp-content/uploads/webp-migrator-temp/* 2>/dev/null || true
```

---

## 🆘 Emergency Shutdown

### **When to Use Emergency Shutdown**
- System is unresponsive
- Containers are consuming too many resources
- Need immediate shutdown
- Normal shutdown procedures are failing

### **Emergency Container Shutdown**
```bash
# Force kill all WebP Migrator containers
podman kill $(podman ps -q --filter name=webp-migrator) 2>/dev/null || true
docker kill $(docker ps -q --filter name=webp-migrator) 2>/dev/null || true

# Force remove containers if kill doesn't work
podman rm -f $(podman ps -aq --filter name=webp-migrator) 2>/dev/null || true
docker rm -f $(docker ps -aq --filter name=webp-migrator) 2>/dev/null || true
```

### **Emergency Process Termination**
```bash
# Kill all related processes (⚠️ Use with caution)
sudo pkill -9 -f "webp-migrator"
sudo pkill -9 -f "apache2.*webp"
sudo pkill -9 -f "mysqld.*webp"

# Kill processes using project ports
sudo kill $(sudo lsof -t -i:8080) 2>/dev/null || true
sudo kill $(sudo lsof -t -i:3307) 2>/dev/null || true
sudo kill $(sudo lsof -t -i:8081) 2>/dev/null || true
```

### **Emergency Cleanup**
```bash
# Remove all project-related containers, volumes, and networks
podman system reset --force  # ⚠️ REMOVES EVERYTHING
docker system prune --all --force --volumes  # ⚠️ REMOVES EVERYTHING

# Manual emergency cleanup
rm -rf ~/webp-migrator-test/ 2>/dev/null || true
```

---

## 🔄 Restart Procedures

### **After Graceful Shutdown**
```bash
# Quick restart (data preserved)
./setup/podman-setup.sh up
# or
./setup/docker-setup.sh up

# WordPress should be available immediately at http://localhost:8080
```

### **After Complete Cleanup**
```bash
# Full setup required
./setup/complete-auto-setup.ps1     # Windows
./setup/instant-deploy.sh           # Linux/macOS

# Or step-by-step
./setup/podman-setup.sh up
./setup/podman-setup.sh install
```

### **Restart Verification**
```bash
# Check all services are running
./setup/podman-setup.sh status

# Test web access
curl -I http://localhost:8080       # Should return 200 or 30x
curl -I http://localhost:8081       # Should return 200 or 30x

# Test database access
mysql -h 127.0.0.1 -P 3307 -u wordpress -pwordpress123 -e "SELECT 1;"
```

---

## 🔧 Shutdown Troubleshooting

### **Container Won't Stop**
```bash
# Check container status
podman ps -a | grep webp-migrator

# Check what's preventing shutdown
podman inspect webp-migrator-wordpress | grep -A 10 -B 10 "State"

# Force stop with timeout
podman stop --timeout 30 webp-migrator-wordpress
```

### **Port Still in Use After Shutdown**
```bash
# Find process using port
sudo lsof -i :8080
sudo netstat -tulpn | grep :8080

# Kill specific process
sudo kill -9 <PID>

# Or kill all processes on port
sudo kill $(sudo lsof -t -i:8080)
```

### **Database Won't Stop**
```bash
# Check database processes
podman exec webp-migrator-mysql mysqladmin processlist -u root -proot123

# Force stop MySQL container
podman kill webp-migrator-mysql

# Check for data corruption (after restart)
podman exec webp-migrator-mysql mysqlcheck -u root -proot123 --all-databases
```

### **Cleanup Script Fails**
```bash
# Manual cleanup steps
podman rm -f webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin webp-migrator-wpcli 2>/dev/null || true
podman volume rm webp-migrator-wp-data webp-migrator-db-data 2>/dev/null || true  
podman network rm webp-migrator-net 2>/dev/null || true
rm -rf ~/webp-migrator-test/ 2>/dev/null || true
```

---

## 📊 Shutdown Checklists

### **Daily Development Shutdown**
- [ ] Check plugin processing status
- [ ] Save any WordPress admin changes
- [ ] Run: `./setup/podman-setup.sh down`
- [ ] Verify: `podman ps | grep webp-migrator` (should be empty)
- [ ] Done! ✅

### **Project Completion Shutdown**
- [ ] Backup important data: `./setup/plugin-manager.sh backup`  
- [ ] Export WordPress content (if needed)
- [ ] Run: `./setup/podman-setup.sh clean`
- [ ] Verify: `podman ps -a | grep webp-migrator` (should be empty)
- [ ] Verify: `podman volume ls | grep webp-migrator` (should be empty)
- [ ] Done! ✅

### **Emergency Shutdown**
- [ ] Run: `podman kill $(podman ps -q --filter name=webp-migrator)`
- [ ] Run: `podman rm -f $(podman ps -aq --filter name=webp-migrator)`
- [ ] Check: `podman ps -a | grep webp-migrator` (should be empty)
- [ ] If needed: `podman system reset --force` ⚠️
- [ ] Done! ✅

---

## 📚 Related Documentation

- **[🚀 Command Cheat Sheet](COMMAND_CHEAT_SHEET.md)** - Quick command reference
- **[📖 Quick Start Guide](QUICK_START.md)** - Setup and restart procedures  
- **[📊 Deployment Guide](DEPLOYMENT_GUIDE.md)** - Complete deployment options
- **[🐧 Bash Scripts Guide](BASH_SCRIPTS_GUIDE.md)** - Linux/macOS specific procedures

---

**💡 Pro Tips:**
- Use `down` for daily shutdown, `clean` for complete removal
- Always check `status` before shutdown to see what's running
- Create backups before complete cleanup if you have custom changes
- Emergency procedures should only be used when normal shutdown fails
- Container environments restart much faster than native installations

**⚠️ Safety Reminders:**
- `clean` commands delete all data - backup first if needed
- Emergency shutdown may cause data loss
- Always verify shutdown completion before considering ports free
- Database containers should be stopped last to prevent corruption
