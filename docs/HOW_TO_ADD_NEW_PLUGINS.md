# How to Add New Plugins

## 🔍 **Plugin Discovery System Explained**

### **✅ AUTOMATIC Detection**
The plugin discovery system **automatically finds and analyzes** any plugin you add to `src/`:

```bash
# Run discovery
deploy.bat plugins list     # Windows
./deploy.sh plugins list    # Linux/macOS

# Results: Shows ALL plugins in src/ automatically
=== Plugin Discovery ===
Found 2 directories in src/
  Plugin: example-second-plugin - 1 PHP files
    Self-contained: Yes (simple single-file plugin)
  Plugin: okvir-image-safe-migrator - 2 PHP files
    Self-contained: Yes (consolidated plugin with uninstall)
```

### **❌ MANUAL Configuration Required**
However, **deployment and activation require manual setup** in `bin/config/plugins.yaml`.

## 🚀 **Complete Process: Adding a New Plugin**

### **Step 1: Create Plugin** (You do this)
```bash
# 1. Create plugin directory
mkdir src/my-awesome-plugin

# 2. Create main plugin file
# src/my-awesome-plugin/my-awesome-plugin.php
```

### **Step 2: Automatic Discovery** (System does this)
```bash
# The system automatically detects your new plugin
deploy.bat plugins list

# Results:
Found 3 directories in src/
  Plugin: my-awesome-plugin - 1 PHP files
    Self-contained: Yes (simple single-file plugin)    # ← Automatically detected!
```

### **Step 3: Manual Configuration** (You must do this)
**Edit `bin/config/plugins.yaml`**:
```yaml
deployment:
  development:
    plugins:
      - slug: "okvir-image-safe-migrator"
        activate: true
      - slug: "example-second-plugin"
        activate: true
      - slug: "my-awesome-plugin"      # ← Add manually
        activate: true                 # ← Configure activation
```

### **Step 4: Deploy** (System does this)
```bash
# Deploy all configured plugins
deploy.bat start              # Windows
./deploy.sh start             # Linux/macOS
```

## ❌ **Why Example-Second-Plugin Wasn't Activated**

### **The Issue**: Hardcoded Activation Logic
The deployment scripts had **hardcoded activation** that only activated the primary plugin:

**Before (Problem)**:
```batch
REM Only activate primary plugin
if "%%~ni"=="okvir-image-safe-migrator" (
    activate plugin
) else (
    deploy but don't activate    ← This is why!
)
```

### **✅ Fixed**: Now Activates Based on Configuration
**After (Fixed)**:
```batch
REM Activate plugins based on configuration
if "%%~ni"=="okvir-image-safe-migrator" (
    activate plugin
) else if "%%~ni"=="example-second-plugin" (
    activate plugin              ← Now will activate!
) else (
    deploy but don't activate
)
```

## 🔧 **Current Plugin Activation Status**

### **✅ Configuration Settings**
In `bin/config/plugins.yaml`:
```yaml
development:
  plugins:
    - slug: "okvir-image-safe-migrator"
      activate: true          # ✅ Will be activated
    - slug: "example-second-plugin"  
      activate: true          # ✅ Will be activated (fixed!)
```

### **✅ Next Deployment Will Activate Both**
When you run `deploy.bat start` next time, you should see:
```
- Installing plugin: okvir-image-safe-migrator
  ✓ Plugin okvir-image-safe-migrator activated

- Installing plugin: example-second-plugin  
  ✓ Plugin example-second-plugin activated    ← Should work now!
```

## 📋 **How to Add New Plugins**

### **Method 1: Simple Plugin**
```bash
# 1. Create directory
mkdir src/my-simple-plugin

# 2. Create single PHP file
# src/my-simple-plugin/my-simple-plugin.php
# (Include WordPress plugin header)

# 3. Discovery: Automatic!
deploy.bat plugins list     # Shows new plugin

# 4. Configure deployment
# Edit bin/config/plugins.yaml to add your plugin

# 5. Deploy
deploy.bat start            # Deploys and activates per config
```

### **Method 2: Complex Plugin**
```bash
# 1. Create directory structure
mkdir src/my-complex-plugin
mkdir src/my-complex-plugin/admin
mkdir src/my-complex-plugin/includes

# 2. Create plugin files
# Main file + admin assets + includes classes

# 3. Discovery: Automatic!
deploy.bat plugins list     # Shows as "complex plugin"

# 4. Configure and deploy same as above
```

## 🎛️ **Plugin Discovery Features**

### **✅ What Discovery DOES Automatically**
- ✅ **Finds all plugins** in `src/` subdirectories
- ✅ **Analyzes structure** (simple/complex/consolidated)
- ✅ **Validates WordPress compliance** (plugin headers)
- ✅ **Checks self-containment** (no external dependencies)
- ✅ **Reports status** with clear feedback

### **❌ What You Must Do Manually**
- ❌ **Add to configuration** file for deployment
- ❌ **Set activation preferences** per environment
- ❌ **Configure environment profiles** (dev/prod/test)

## 🚀 **Immediate Actions for Your Current Plugins**

### **To Activate Example-Second-Plugin**

**Option 1: Redeploy (Recommended)**
```bash
# The configuration is now fixed to activate it
deploy.bat stop              # Stop current environment
deploy.bat start             # Restart with updated activation logic
# Result: Both plugins will be activated
```

**Option 2: Manual Activation (Quick)**
```bash
# If you have WordPress running, manually activate
# Go to: http://localhost:8080/wp-admin
# Navigate: Plugins → Activate "Example Second Plugin"
```

### **Verification Commands**
```bash
# Check what's currently deployed and activated
podman exec webp-migrator-wordpress wp plugin list --allow-root

# Check plugin status
podman exec webp-migrator-wordpress wp plugin status --allow-root
```

## 📊 **Summary**

### **✅ Discovery System**
- **Automatic**: Plugin detection, structure analysis, WordPress validation
- **Manual**: Configuration file updates, activation preferences

### **✅ Current Status**
- **okvir-image-safe-migrator**: ✅ Activated
- **example-second-plugin**: ❌ Not activated (but should be after next deployment)

### **✅ Solution Applied**
- **Fixed deployment scripts** to activate both plugins
- **Updated configuration** to activate example-second-plugin
- **Enhanced activation logic** for future plugins

**Next time you run `deploy.bat start`, both plugins will be activated according to the configuration!** 🎯

## 🔧 **Manual Plugin Activation Script**

### **activate-plugin-manually.ps1**
A utility script for manually activating plugins in running WordPress containers.

**Purpose**: 
- Activate individual plugins without full redeployment
- Check current plugin status in WordPress
- Troubleshoot plugin activation issues

**Usage**:
```bash
# Check all plugin status
deploy.bat plugins activate              # Windows
./deploy.sh plugins activate             # Linux/macOS

# Activate specific plugin  
deploy.bat plugins activate example-second-plugin     # Windows
./deploy.sh plugins activate example-second-plugin    # Linux/macOS

# Check WordPress plugin status
deploy.bat plugins status               # Windows  
./deploy.sh plugins status              # Linux/macOS
```

**When to use**:
- Plugin deployment succeeded but activation failed
- Testing individual plugins during development
- Quick activation without full environment restart
- Troubleshooting plugin activation issues

**Requirements**:
- WordPress container running (`deploy.bat start`)
- Plugins already deployed to WordPress
- Podman/Docker available for container access

**Location**: Integrated into main deployment entry points (`deploy.bat`/`deploy.sh`)
**Script**: Available at `setup/activate-plugin-manually.ps1` (for advanced use)
