# Entry Points Migration Guide

## 🎯 Answer: **Entry points have been updated and enhanced!**

The main entry points have been **renamed and enhanced**:
- **`webp-migrator.bat`** → **`deploy.bat`** (Windows)  
- **`webp-migrator.sh`** → **`deploy.sh`** (Linux/macOS)

**Plus new test entry points:**
- **`test.bat`** (Windows test framework)
- **`test.sh`** (Linux/macOS test framework)

## 📋 What's Changed

### ✅ **New Enhanced Entry Points**
- ✅ `deploy.bat start` - Enhanced Windows deployment (renamed from webp-migrator.bat)
- ✅ `deploy.sh start` - Enhanced Linux/macOS deployment (renamed from webp-migrator.sh)
- ✅ `test.bat system` - New Windows test framework
- ✅ `test.sh system` - New Linux/macOS test framework
- ✅ All existing commands preserved: `stop`, `clean`, `status`, `download`, `manage`, `fix`

### 🆕 Enhanced with Multi-Plugin Support
The new entry points now:
- Deploy **any number of plugins** from the `src/` directory based on configuration
- Support **deployment profiles** (development, production, testing, custom)
- Use **Okvir Image Safe Migrator** (renamed from WebP Safe Migrator)
- Show **multi-plugin management commands** in help
- Provide **comprehensive test framework** for validation

## 🚀 Usage - Enhanced but Simple!

### Quick Start (New Commands)
```bash
# Windows
deploy.bat start

# Linux/macOS  
./deploy.sh start
```

### All Commands Enhanced
```bash
# Windows Examples
deploy.bat start             # Start multi-plugin environment
deploy.bat stop              # Stop containers  
deploy.bat clean             # Complete cleanup
deploy.bat status            # Show status
deploy.bat download          # Pre-download resources
deploy.bat plugins list      # List available plugins
deploy.bat fix               # Fix permissions

# Linux/macOS Examples
./deploy.sh start            # Start multi-plugin environment
./deploy.sh stop             # Stop containers
./deploy.sh clean            # Complete cleanup
./deploy.sh status           # Show status
./deploy.sh download         # Pre-download resources
./deploy.sh plugins list     # List available plugins
./deploy.sh fix              # Fix permissions
```

## 🔧 What Happens When You Start

### Before (Single Plugin)
```
webp-migrator.bat start
├── Started WordPress
├── Mounted: src/ → /wp-content/plugins/webp-safe-migrator/
└── Activated: webp-safe-migrator
```

### After (Multi-Plugin, Self-Contained)
```  
deploy.bat start
├── Started WordPress
├── Configuration-driven plugin deployment:
│   ├── src/okvir-image-safe-migrator/ (self-contained: includes/, admin/)
│   ├── src/example-second-plugin/ (self-contained)
│   └── src/[any-future-plugins]/ (self-contained)
├── Deployed plugins based on profile (development/production/testing)
├── Fixed permissions once for all plugins  
└── Activated plugins according to configuration
```

## 🆕 New Multi-Plugin Commands

While your main entry points work the same, you now have **additional** multi-plugin management commands:

### Windows PowerShell
```powershell
# List available plugins
setup\multi-plugin-manager.ps1 list

# Deploy all development plugins  
setup\multi-plugin-manager.ps1 install-all --profile development

# Deploy production plugins only
setup\multi-plugin-manager.ps1 install-all --profile production

# Show plugin status
setup\multi-plugin-manager.ps1 status
```

### Linux/macOS Bash
```bash
# List available plugins
./setup/multi-plugin-manager.sh list

# Deploy all development plugins
./setup/multi-plugin-manager.sh install-all --profile development  

# Deploy production plugins only
./setup/multi-plugin-manager.sh install-all --profile production

# Show plugin status
./setup/multi-plugin-manager.sh status
```

## 📂 What You'll See Now

### When You Run `webp-migrator.bat start`:
```
=====================================
   Multi-Plugin WordPress Dev Environment v2.0
=====================================

🚀 Starting Multi-Plugin WordPress environment...

Starting database...
* Database: wordpress_webp_test
* User: wordpress

Starting WordPress...
* Port: 8080
* Site: http://localhost:8080

Deploying plugins using multi-plugin manager...
* Copying plugins to WordPress container...
  - Copying plugin: okvir-image-safe-migrator
  - Copying plugin: example-second-plugin

* Activating Okvir Image Safe Migrator...
* Primary plugin activated successfully!

=====================================
     SUCCESS - Multi-Plugin Environment Ready!
=====================================

WordPress: http://localhost:8080/wp-admin
Username:  admin
Password:  admin123

phpMyAdmin: http://localhost:8081
Primary Plugin: Media → Image Migrator
Plugin Management: setup\multi-plugin-manager.ps1
```

## 🎛️ Plugin Management

### Quick Plugin Management
The main entry points now show you the multi-plugin management commands in their help:

```bash
webp-migrator.bat help
# or
./webp-migrator.sh help
```

You'll see:
```
MULTI-PLUGIN MANAGEMENT:
  setup\multi-plugin-manager.ps1 list        # List available plugins  
  setup\multi-plugin-manager.ps1 status      # Show plugin deployment status
```

## 🔄 Migration Summary

| Aspect | Before | After | Action Required |
|--------|--------|-------|-----------------|
| **Entry Points** | `webp-migrator.bat start` | `webp-migrator.bat start` | ✅ **None - works the same!** |
| **Plugin Structure** | Single plugin in `src/` | Multiple plugins in `src/subfolders/` | ✅ **Auto-detected** |
| **Plugin Name** | WebP Safe Migrator | Okvir Image Safe Migrator | ✅ **Auto-updated** |
| **Commands** | All existing commands | All existing commands + new multi-plugin commands | ✅ **Backward compatible** |

## 💡 Recommendations

### For Existing Workflows
- **Continue using `webp-migrator.bat start`** - it now automatically handles multiple plugins
- **No changes needed** to your existing startup process

### For New Multi-Plugin Development  
- Use `setup\multi-plugin-manager.ps1 list` to see all available plugins
- Use `setup\multi-plugin-manager.ps1 install-all --profile development` for advanced plugin management
- Edit `bin/config/plugins.yaml` to configure which plugins deploy in which environments

## 🎉 Benefits You Get Now

### Same Interface, More Power
- **Same commands** you're used to
- **Multiple plugins** automatically deployed  
- **Environment profiles** (development, production, testing)
- **Selective plugin activation** when needed
- **Better organization** with plugin isolation

### Backward Compatibility
- **All existing scripts work**
- **All existing workflows preserved**  
- **Gradual adoption** of new features
- **No breaking changes**

---

## 🚀 Quick Start (Same as Always!)

```bash
# Just run the same command you always used:
webp-migrator.bat start
```

**That's it!** Your environment now supports multiple plugins, but you use it exactly the same way. The multi-plugin features are there when you need them, but don't get in the way of your existing workflow.

The entry points remain the same - they're just more powerful now! 🎯
