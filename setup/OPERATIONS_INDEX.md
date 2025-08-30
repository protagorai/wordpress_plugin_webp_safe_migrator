# 🎛️ WebP Safe Migrator - Operations Index

**Quick navigation to common operations** - Click the links for detailed instructions.

## 🚀 **I Want To...**

### **Get Started**
- **[🎯 Set up everything in one command](QUICK_START.md#-instant-deployment-recommended)** → Use `instant-deploy.ps1` or `instant-deploy.sh`
- **[⚙️ Customize configuration](README-CONFIG-SYSTEM.md)** → Templates for usernames, passwords, domains, etc.
- **[🔧 Set up with custom options](DEPLOYMENT_GUIDE.md#-deployment-options)** → Choose from multiple deployment methods
- **[📖 Learn all available commands](COMMAND_CHEAT_SHEET.md)** → Complete command reference

### **Daily Operations**
- **[▶️ Start the development environment](COMMAND_CHEAT_SHEET.md#-launch-commands)** → `./setup/podman-setup.sh up`
- **[⏹️ Stop the development environment](GRACEFUL_SHUTDOWN.md#-container-environment-shutdown)** → `./setup/podman-setup.sh down`  
- **[📊 Check what's running](COMMAND_CHEAT_SHEET.md#-status--monitoring)** → `./setup/podman-setup.sh status`
- **[🔍 View logs](COMMAND_CHEAT_SHEET.md#log-monitoring)** → `./setup/docker-setup.sh logs --follow`

### **Plugin Development**
- **[🔧 Update plugin after code changes](COMMAND_CHEAT_SHEET.md#plugin-development)** → `./setup/plugin-manager.sh update`
- **[💾 Backup plugin before changes](COMMAND_CHEAT_SHEET.md#plugin-development)** → `./setup/plugin-manager.sh backup`
- **[📋 Check plugin status](COMMAND_CHEAT_SHEET.md#plugin-development)** → `./setup/plugin-manager.sh status`

### **Cleanup & Reset**
- **[🧹 Clean up everything](GRACEFUL_SHUTDOWN.md#-complete-cleanup)** → `./setup/podman-setup.sh clean`
- **[🔄 Start fresh](COMMAND_CHEAT_SHEET.md#fresh-start)** → Clean + redeploy
- **[🆘 Emergency shutdown](GRACEFUL_SHUTDOWN.md#-emergency-shutdown)** → Force stop everything

### **Configuration & Customization**
- **[⚙️ Quick config examples](CONFIG_EXAMPLES.md)** → Copy-paste examples for common setups
- **[🔐 Change passwords/usernames](CONFIG_EXAMPLES.md#change-database-credentials)** → Database users, WordPress admin, etc.
- **[🌐 Custom domains/ports](CONFIG_EXAMPLES.md#custom-domain-setup)** → Set custom URLs and ports
- **[🔧 Complete config guide](README-CONFIG-SYSTEM.md)** → Full documentation with all options

### **Troubleshooting**
- **[❓ Common issues](QUICK_START.md#-troubleshooting)** → Port conflicts, container issues
- **[🔧 Shutdown problems](GRACEFUL_SHUTDOWN.md#-shutdown-troubleshooting)** → Won't stop, port still in use
- **[🚨 Emergency procedures](COMMAND_CHEAT_SHEET.md#-emergency-commands)** → Force stop, recovery

---

## ⚡ **One-Liner Commands**

### **Instant Setup**
```bash
# Windows
.\setup\complete-auto-setup.ps1

# Linux/macOS  
./setup/instant-deploy.sh
```

### **Daily Workflow**
```bash
./setup/podman-setup.sh up      # Start
./setup/podman-setup.sh status  # Check
./setup/podman-setup.sh down    # Stop
./setup/podman-setup.sh clean   # Reset
```

### **Configuration Setup**
```bash
# Quick config with templates
cp setup/simple-config.yaml my-config.yaml        # Copy template
# Edit my-config.yaml (usernames, passwords, domains)
./setup/generate-config.sh my-config.yaml         # Generate setup
```

### **Plugin Development**
```bash
./setup/plugin-manager.sh update    # Update plugin
./setup/plugin-manager.sh backup    # Backup first
./setup/plugin-manager.sh status    # Check status
```

---

## 🎯 **Quick Access URLs**

After setup, access your development environment:

| Service | URL | Purpose |
|---------|-----|---------|
| **🌐 WordPress** | [http://localhost:8080](http://localhost:8080) | Main website |
| **🚀 Auto-Login** | [http://localhost:8080/?auto_login=dev_mode](http://localhost:8080/?auto_login=dev_mode) | Skip login |
| **🔧 WordPress Admin** | [http://localhost:8080/wp-admin](http://localhost:8080/wp-admin) | Admin panel |
| **🔌 Plugin Interface** | Media → WebP Migrator | Plugin settings |
| **🗄️ Database Admin** | [http://localhost:8081](http://localhost:8081) | phpMyAdmin |

**Default Credentials:** admin / admin123!

---

## 📚 **Detailed Documentation**

| Guide | Purpose | When to Use |
|-------|---------|-------------|
| **[🚀 Quick Start](QUICK_START.md)** | Complete setup walkthrough | First time setup |
| **[🎛️ Command Cheat Sheet](COMMAND_CHEAT_SHEET.md)** | All commands reference | Daily operations |
| **[🛑 Graceful Shutdown](GRACEFUL_SHUTDOWN.md)** | Proper shutdown procedures | Stopping safely |
| **[⚙️ Configuration Examples](CONFIG_EXAMPLES.md)** | Copy-paste config examples | Quick customization |
| **[📋 Configuration System](README-CONFIG-SYSTEM.md)** | Complete customization guide | Advanced configurations |
| **[📖 Deployment Guide](DEPLOYMENT_GUIDE.md)** | Advanced deployment options | Custom setups |
| **[🐧 Bash Scripts Guide](BASH_SCRIPTS_GUIDE.md)** | Linux/macOS specific docs | Unix systems |
| **[🪟 Plugin Manager Guide](PLUGIN_MANAGER_GUIDE.md)** | Plugin lifecycle management | Plugin development |
| **[🌐 Cross-Platform Summary](CROSS_PLATFORM_SUMMARY.md)** | Platform comparison | Choose your setup |

---

## 🔥 **Most Common Tasks**

### **First Time Setup (Default)**
1. **[Clone/download project](../README.md)**
2. **[Run instant setup](QUICK_START.md#-instant-deployment-recommended)**: `./setup/instant-deploy.sh`
3. **[Open WordPress](http://localhost:8080/?auto_login=dev_mode)** (auto-login)
4. **Navigate to Media → WebP Migrator**
5. **Upload test images and start converting!**

### **First Time Setup (Custom)**
1. **[Clone/download project](../README.md)**
2. **[Copy config template](README-CONFIG-SYSTEM.md#-quick-start)**: `cp setup/simple-config.yaml my-config.yaml`
3. **Edit my-config.yaml**: Change usernames, passwords, domains, ports
4. **[Generate setup](README-CONFIG-SYSTEM.md#-generate-configuration-files)**: `./setup/generate-config.sh my-config.yaml`
5. **Deploy**: `cd setup/generated/ && docker-compose up -d`
6. **[Open your custom WordPress URL](http://localhost:8080/?auto_login=dev_mode)**

### **Daily Development**
1. **Start**: `./setup/podman-setup.sh up`
2. **[Open WordPress](http://localhost:8080/?auto_login=dev_mode)**
3. **Edit files in `src/` directory**
4. **Update plugin**: `./setup/plugin-manager.sh update`
5. **Test changes in browser**
6. **Stop when done**: `./setup/podman-setup.sh down`

### **End of Project**
1. **[Backup important data](COMMAND_CHEAT_SHEET.md#development-commands)**: `./setup/plugin-manager.sh backup`
2. **[Complete cleanup](GRACEFUL_SHUTDOWN.md#-container-environment-shutdown)**: `./setup/podman-setup.sh clean`
3. **Verify cleanup**: `podman ps` (should be empty)

---

## 💡 **Pro Tips**

- **Bookmark** [http://localhost:8080/?auto_login=dev_mode](http://localhost:8080/?auto_login=dev_mode) for instant access
- **Use `podman` instead of `docker`** - no licensing restrictions
- **Always run `status` first** to see what's currently running
- **Use `down` for daily stops**, `clean` only for complete reset
- **Keep containers running** between development sessions for speed
- **Create backups before major changes** with plugin manager

## ⚠️ **Important Notes**

- **`clean` commands delete ALL data** - backup first if needed
- **Auto-login is for development only** - don't use in production
- **Default ports**: 8080 (web), 3307 (MySQL), 8081 (phpMyAdmin)
- **Container names**: `webp-migrator-*` - used in all commands
- **Plugin location**: Media → WebP Migrator in WordPress admin

---

## 🆘 **Emergency Help**

**Something's not working?**

1. **[Check status](COMMAND_CHEAT_SHEET.md#-status--monitoring)**: `./setup/podman-setup.sh status`
2. **[View logs](COMMAND_CHEAT_SHEET.md#log-monitoring)**: `./setup/docker-setup.sh logs --follow`
3. **[Try clean restart](COMMAND_CHEAT_SHEET.md#clean-restart-after-problems)**: `clean` → `up` → `install`
4. **[Emergency stop](GRACEFUL_SHUTDOWN.md#-emergency-shutdown)**: Force kill everything
5. **[Check common issues](QUICK_START.md#-troubleshooting)**: Port conflicts, container problems

**Still stuck?** Check the **[troubleshooting sections](GRACEFUL_SHUTDOWN.md#-shutdown-troubleshooting)** in each guide.

---

**📅 Last Updated:** January 27, 2025 | **📋 Version:** 2.0
