# How Plugin Discovery Works

## 🔍 **Current Plugin Discovery System**

### **✅ What's AUTOMATIC**
The plugin discovery system **automatically detects**:

1. **Plugin Existence**: Scans `src/` directory for subdirectories
2. **Plugin Structure**: Analyzes each plugin's file structure
3. **Plugin Type**: Determines if simple, complex, or consolidated
4. **WordPress Compliance**: Checks for valid plugin headers
5. **Self-Containment**: Validates plugin independence

### **❌ What's MANUAL** 
The system **requires manual configuration** for:

1. **Deployment Configuration**: Add to `bin/config/plugins.yaml`
2. **Activation Settings**: Choose which plugins to activate per environment
3. **Environment Profiles**: Configure dev/prod/test deployment rules

## 🎛️ **Discovery Process Explained**

### **Step 1: Automatic Scanning**
```powershell
# Run plugin discovery
powershell -File setup\clean-plugin-list.ps1

# Results: Automatically detects ALL plugins in src/
=== Plugin Discovery ===
Found 2 directories in src/
  Plugin: example-second-plugin - 1 PHP files
    Self-contained: Yes (simple single-file plugin)
  Plugin: okvir-image-safe-migrator - 2 PHP files  
    Self-contained: Yes (consolidated plugin with uninstall)
Plugin discovery completed successfully
```

### **Step 2: Structure Analysis**
The system **automatically determines**:
- **Simple Plugin**: 1 PHP file (e.g., `example-second-plugin.php`)
- **Complex Plugin**: Has `admin/` AND `includes/` folders
- **Consolidated Plugin**: Main file + `uninstall.php` (your Okvir plugin)
- **Partial**: Multiple files but missing expected structure

### **Step 3: WordPress Validation**
**Automatically checks**:
- ✅ Valid PHP syntax
- ✅ WordPress plugin header (`Plugin Name: ...`)
- ✅ Plugin compliance

## 📋 **Manual Configuration Required**

### **❌ NOT Automatic**: Configuration File Updates
When you add a new plugin, you **must manually update** `bin/config/plugins.yaml`:

**Current Configuration**:
```yaml
deployment:
  development:
    plugins:
      - slug: "okvir-image-safe-migrator"
        activate: true
      - slug: "example-second-plugin"
        activate: false
```

**To Add New Plugin**:
```yaml
deployment:
  development:
    plugins:
      - slug: "okvir-image-safe-migrator"
        activate: true
      - slug: "example-second-plugin"
        activate: false
      - slug: "my-new-plugin"           # ← Manual addition required
        activate: true                 # ← Manual configuration required
```

## 🚀 **Demo: Adding a New Plugin**

Let me demonstrate the complete process:

### **Step 1: Create New Plugin** (Automatic Detection)
