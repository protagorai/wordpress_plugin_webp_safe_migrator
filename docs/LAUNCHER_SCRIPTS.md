# 🚀 WebP Safe Migrator - Launcher Scripts

Simple, reliable scripts to launch and manage your WebP Safe Migrator development environment.

## 📋 Available Scripts

### **Windows (Batch Files)**
| Script | Purpose | What it does |
|--------|---------|--------------|
| `launch-webp-migrator.bat` | 🚀 **Start Everything** | Complete deployment with WordPress + Plugin |
| `stop-webp-migrator.bat` | ⏹️ **Stop Safely** | Stop containers (preserve data) |
| `cleanup-webp-migrator.bat` | 🧹 **Complete Cleanup** | Remove everything (⚠️ deletes all data) |
| `status-webp-migrator.bat` | 📊 **Check Status** | Show what's running and accessible |

### **Linux/macOS (Shell Scripts)**
| Script | Purpose | What it does |
|--------|---------|--------------|
| `launch-webp-migrator.sh` | 🚀 **Start Everything** | Complete deployment with WordPress + Plugin |
| `stop-webp-migrator.sh` | ⏹️ **Stop Safely** | Stop containers (preserve data) |
| `cleanup-webp-migrator.sh` | 🧹 **Complete Cleanup** | Remove everything (⚠️ deletes all data) |
| `status-webp-migrator.sh` | 📊 **Check Status** | Show what's running and accessible |
| `launch-universal.sh` | 🌐 **Auto-Detect** | Detects platform and runs appropriate script |

## ⚡ Quick Usage

### **First Time Setup**
```bash
# Windows
.\launch-webp-migrator.bat

# Linux/macOS
./launch-webp-migrator.sh

# Universal (auto-detects platform)
./launch-universal.sh
```

### **Daily Management**
```bash
# Check what's running
.\status-webp-migrator.bat    # Windows
./status-webp-migrator.sh     # Linux/macOS

# Stop for the day (keeps data)
.\stop-webp-migrator.bat      # Windows
./stop-webp-migrator.sh       # Linux/macOS

# Start again next day
.\launch-webp-migrator.bat    # Windows
./launch-webp-migrator.sh     # Linux/macOS
```

### **Complete Reset**
```bash
# Remove everything and start fresh
.\cleanup-webp-migrator.bat   # Windows
./cleanup-webp-migrator.sh    # Linux/macOS

# Then launch again
.\launch-webp-migrator.bat    # Windows
./launch-webp-migrator.sh     # Linux/macOS
```

## 🔧 What Gets Deployed

### **Containers Created**
- **webp-migrator-mysql** - MySQL 8.0 database
- **webp-migrator-wordpress** - WordPress with plugin mounted
- **webp-migrator-phpmyadmin** - Database management interface

### **Network Configuration**
- **Network**: `webp-migrator-net`
- **WordPress**: http://localhost:8080
- **phpMyAdmin**: http://localhost:8081
- **MySQL**: localhost:3307

### **Credentials**
- **WordPress Admin**: admin / admin123
- **Database User**: wordpress / wordpress123
- **Database Root**: root / root123

## 🛠️ Script Features

### **Built-in Safety**
- ✅ **Automatic cleanup** of existing containers before starting
- ✅ **Path validation** ensures scripts run from correct directory
- ✅ **Prerequisite checking** verifies Podman is installed
- ✅ **Service readiness** waits for MySQL and WordPress to be ready
- ✅ **Error handling** with clear error messages

### **No Policies/Permissions Required**
- ✅ **PowerShell execution policy bypass** built into batch files
- ✅ **No admin privileges** required (uses non-privileged ports)
- ✅ **Cross-platform compatibility** with platform detection
- ✅ **Self-contained** - no external dependencies beyond Podman

### **Automated WordPress Setup**
- ✅ **WordPress core installation** via WP-CLI
- ✅ **Plugin activation** automatically
- ✅ **Sample content creation** for testing
- ✅ **Browser launch** opens WordPress automatically

## 🔄 Development Workflow

### **Daily Development (Live Editing)**
```bash
1. .\launch-webp-migrator.bat     # Start environment (once)
2. # Edit files in src/ directory
3. # Refresh WordPress admin - changes appear immediately
4. # NO container restart needed for code changes!
5. .\stop-webp-migrator.bat       # Stop when done (data preserved)
```

**Key: The `src/` directory is mounted as a live volume - PHP changes are instant!**

### **When Container Restart IS Needed**
```bash
# Only restart for these changes:
# - PHP configuration (upload limits, etc.)
# - Apache configuration  
# - Database schema changes
# - Container configuration changes
```

### **When Container Restart is NOT Needed**
```bash
# Live changes for:
# - Plugin PHP code editing
# - WordPress content changes
# - Database data changes (via admin/WP-CLI)
# - Plugin settings and configuration
```

### **Project Completion**
```bash
1. .\cleanup-webp-migrator.bat    # Remove everything
2. # Project workspace is clean
```

## 🆘 Troubleshooting

### **Scripts Won't Run**
```bash
# Windows: PowerShell execution policy
# SOLUTION: Scripts use -ExecutionPolicy Bypass automatically

# Linux/macOS: Permission denied
chmod +x *.sh
./launch-webp-migrator.sh
```

### **Port Conflicts**
```bash
# Check what's using ports
netstat -an | findstr :8080    # Windows
netstat -an | grep :8080       # Linux/macOS

# Kill processes using ports
# Scripts automatically clean up WebP Migrator containers
```

### **Container Issues**
```bash
# Complete reset
.\cleanup-webp-migrator.bat    # Windows
./cleanup-webp-migrator.sh     # Linux/macOS

# Then start fresh
.\launch-webp-migrator.bat     # Windows
./launch-webp-migrator.sh      # Linux/macOS
```

### **MySQL Won't Start**
```bash
# Check MySQL logs
podman logs webp-migrator-mysql

# Common issues:
# - Port 3307 already in use
# - Previous MySQL data corruption
# Solution: Use cleanup script then launch again
```

## 💡 Pro Tips

- **Use status script** before launching to see what's already running
- **Stop vs Cleanup**: Stop preserves data, Cleanup removes everything
- **Plugin development**: Edit files in `src/` - changes appear immediately
- **Database access**: Use phpMyAdmin at http://localhost:8081 for direct DB access
- **WordPress access**: Use admin/admin123 for immediate access

## 📚 Related Documentation

- **[🎛️ Operations Index](../setup/OPERATIONS_INDEX.md)** - Complete task navigation
- **[🎯 Command Cheat Sheet](../setup/COMMAND_CHEAT_SHEET.md)** - All commands reference
- **[🛑 Graceful Shutdown Guide](../setup/GRACEFUL_SHUTDOWN.md)** - Detailed shutdown procedures
- **[🚀 Quick Start Guide](../setup/QUICK_START.md)** - Complete setup walkthrough

---

**🎯 Bottom Line**: These scripts provide the simplest way to get WordPress + WebP Safe Migrator running for development. Just run the launcher script for your platform and start developing!
