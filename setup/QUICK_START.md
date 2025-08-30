# 🚀 WebP Safe Migrator - Quick Start Guide

## ⚡ **Instant Deployment (Recommended)**

Choose your platform and run **ONE COMMAND** for complete setup:

### **Windows (PowerShell)**
```powershell
cd setup
.\instant-deploy.ps1
```

### **Linux/macOS/WSL (Bash)**
```bash
cd setup
./instant-deploy.sh
```

## 🎯 **What Happens Automatically**

The instant deployment script will:

1. ✅ **Clean Environment** - Remove any existing containers
2. ✅ **Download Images** - Pull MySQL, WordPress, phpMyAdmin containers  
3. ✅ **Setup Database** - Create optimized MySQL with proper credentials
4. ✅ **Install WordPress** - Latest version with English locale
5. ✅ **Fix Plugin Issues** - Automatically fix any syntax errors
6. ✅ **Activate Plugin** - Enable WebP Safe Migrator plugin
7. ✅ **Create Content** - Add welcome page with instructions
8. ✅ **Auto-Login Setup** - Generate automatic login URLs
9. ✅ **Launch Browser** - Open WordPress with auto-login

## 🌐 **Access URLs After Setup**

| Service | URL | Credentials |
|---------|-----|-------------|
| **🌐 WordPress** | `http://localhost:8080` | N/A |
| **🚀 Auto-Login** | `http://localhost:8080/?auto_login=dev_mode` | **← USE THIS** |
| **🔧 Admin Panel** | `http://localhost:8080/wp-admin` | admin / admin123! |
| **🔌 Plugin** | Media → WebP Migrator | Same as admin |
| **🗄️ Database** | `http://localhost:8081` | root / root123 |

## ⚡ **Ultra-Quick Start**

1. **Run the script**: `.\instant-deploy.ps1` (Windows) or `./instant-deploy.sh` (Linux/Mac)
2. **Wait 2-3 minutes** for complete setup
3. **WordPress opens automatically** with everything ready
4. **Go to Media → WebP Migrator** to start testing!

## 🔧 **Advanced Options**

### **Complete Setup with Options**
```powershell
# Custom ports and settings
.\complete-auto-setup.ps1 -HttpPort 9080 -MySQLPort 3308 -SkipBrowser
```

### **Configuration-Based Setup**
```bash
# Use YAML configuration
cp simple-config.yaml my-setup.yaml
./generate-config.sh my-setup.yaml --auto-install
```

## 🛠️ **Management Commands**

### **Check Status**
```bash
podman ps  # or docker ps
```

### **Stop Everything**
```bash
podman stop webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin
```

### **Start Everything**
```bash
podman start webp-migrator-mysql webp-migrator-wordpress webp-migrator-phpmyadmin
```

### **Complete Cleanup**
```bash
podman rm -f webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin
podman network rm webp-migrator-net
```

## 🎯 **Testing the Plugin**

After setup completes:

1. **Browser opens automatically** to WordPress
2. **Login happens automatically** (or use admin/admin123!)
3. **Go to Media → WebP Migrator**
4. **Upload test images** (JPEG, PNG, GIF)
5. **Configure settings**: Quality 75, Batch size 10
6. **Click "Process next batch"**
7. **Review results** and commit changes

## ❓ **Troubleshooting**

### **Port Issues**
```bash
# Use different ports
.\complete-auto-setup.ps1 -HttpPort 9080 -MySQLPort 3308
```

### **Container Issues**
```bash
# Force clean restart
podman system prune -f
.\instant-deploy.ps1
```

### **Plugin Issues**
The setup automatically fixes known plugin syntax errors. If you encounter issues:
- Check `Media → Plugins` to ensure WebP Safe Migrator is activated
- Check the plugin source in `src/` directory

## 🚀 **That's It!**

The new setup system handles everything automatically. Just run the script and start developing!

**Pro tip**: Use the auto-login URL (`http://localhost:8080/?auto_login=dev_mode`) to skip manual login every time!

