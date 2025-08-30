# 🚀 WebP Safe Migrator - Deployment Guide

This guide covers the improved, fully automated deployment system that fixes all the issues encountered during manual setup.

## ✅ **Issues Fixed**

Based on our testing session, the following issues have been resolved:

### 1. **Port Binding Issues**
- ❌ **Problem**: Rootless Podman can't bind to privileged ports (80, 443)
- ✅ **Solution**: Uses non-privileged ports by default (8080, 8443, 3307)
- 🔧 **Implementation**: Automatic port detection and fallback

### 2. **Container Name Conflicts**  
- ❌ **Problem**: "Container name already in use" errors
- ✅ **Solution**: Automatic cleanup of existing containers
- 🔧 **Implementation**: `--clean-start` option and interactive cleanup

### 3. **PowerShell Script Syntax Errors**
- ❌ **Problem**: Syntax errors in PowerShell scripts preventing execution
- ✅ **Solution**: Completely rewritten error-free PowerShell scripts
- 🔧 **Implementation**: Proper escaping and robust error handling

### 4. **Manual Setup Process**
- ❌ **Problem**: Required multiple manual steps and configuration
- ✅ **Solution**: Fully automated end-to-end deployment
- 🔧 **Implementation**: Single-command deployment with WordPress installation

### 5. **Service Readiness Issues**
- ❌ **Problem**: Containers started but services not ready
- ✅ **Solution**: Proper wait logic and health checks
- 🔧 **Implementation**: Database ping tests and HTTP response validation

## 🎯 **Deployment Options**

### **Option 1: One-Click Deployment (Recommended)**

The simplest way to get started:

#### **Windows (PowerShell):**
```powershell
cd setup
.\one-click-deploy.ps1
```

#### **Linux/macOS/WSL (Bash):**
```bash
cd setup
./one-click-deploy.sh
```

**What it does:**
- ✅ Automatically downloads all container images
- ✅ Creates and configures all containers with correct names
- ✅ Installs WordPress with optimal settings  
- ✅ Activates WebP Safe Migrator plugin
- ✅ Creates sample content for testing
- ✅ Opens WordPress in your browser

### **Option 2: Advanced Deployment**

For more control over the deployment process:

#### **Windows (PowerShell):**
```powershell
cd setup
.\webp-migrator-deploy.ps1 [OPTIONS]
```

**Available Options:**
- `-CleanStart` - Remove existing containers first
- `-SkipBrowser` - Don't open browser automatically
- `-HttpPort 9080` - Use custom HTTP port
- `-MySQLPort 3307` - Use custom MySQL port
- `-InstallPath "C:\my-webp-test"` - Custom installation directory

#### **Linux/macOS/WSL (Bash):**
```bash
cd setup
./webp-migrator-deploy.sh [OPTIONS]
```

**Available Options:**
- `--clean-start` - Remove existing containers first
- `--skip-browser` - Don't open browser automatically  
- `--http-port 9080` - Use custom HTTP port
- `--mysql-port 3307` - Use custom MySQL port
- `--install-path ~/my-webp-test` - Custom installation directory

### **Option 3: Configuration-Based Deployment**

Use our advanced configuration system:

```bash
# 1. Create your configuration
cp simple-config.yaml my-config.yaml
# Edit my-config.yaml as needed

# 2. Generate deployment files
./generate-config.sh my-config.yaml --auto-install
```

## 📊 **What Gets Deployed**

### **Container Architecture:**
```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   WordPress     │    │     MySQL       │    │   phpMyAdmin    │
│   (Port 8080)   │────│   (Port 3307)   │────│   (Port 8081)   │
│                 │    │                 │    │                 │
│ • WordPress     │    │ • Database      │    │ • DB Management │
│ • Apache        │    │ • User Setup    │    │ • Web Interface │
│ • PHP 8.2       │    │ • UTF8MB4       │    │ • File Upload   │
│ • Plugin Mount  │    │ • Optimization  │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                       │                       │
         └───────────────────────┼───────────────────────┘
                                 │
                    ┌─────────────────┐
                    │     WP-CLI      │
                    │                 │
                    │ • WordPress     │
                    │   Management    │
                    │ • Plugin Mgmt   │
                    │ • Content Mgmt  │
                    └─────────────────┘
```

### **Automatic Configuration:**
- **WordPress**: Latest version with optimal PHP settings
- **Database**: MySQL 8.0 with UTF8MB4 and performance tuning
- **Plugin**: WebP Safe Migrator pre-installed and activated
- **Content**: Sample pages and test content created
- **Security**: Secure passwords and proper file permissions
- **Development**: Debug logging enabled for development

## 🔑 **Access Information**

After successful deployment, you'll have access to:

### **🌐 WordPress Website**
- **URL**: `http://localhost:8080`
- **Purpose**: Main website and testing area

### **🔧 WordPress Admin**  
- **URL**: `http://localhost:8080/wp-admin`
- **Username**: `admin`
- **Password**: `admin123!`
- **Purpose**: WordPress dashboard and plugin management

### **🗄️ Database Management**
- **URL**: `http://localhost:8081`
- **Purpose**: phpMyAdmin for direct database access
- **Database**: `wordpress_webp_test`
- **User**: `wordpress` / `wordpress123`
- **Root**: `root` / `root123`

### **🔌 Plugin Access**
- **Location**: Media → WebP Migrator
- **Purpose**: Test WebP conversion features
- **Features**: Batch processing, quality settings, validation

## 🛠️ **Container Management**

### **Check Status:**
```bash
podman ps
# or
docker ps
```

### **Stop All Containers:**
```bash
podman stop webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin webp-migrator-wpcli
```

### **Start All Containers:**
```bash
podman start webp-migrator-mysql webp-migrator-wordpress webp-migrator-phpmyadmin webp-migrator-wpcli
```

### **Complete Cleanup:**
```bash
podman rm -f webp-migrator-wordpress webp-migrator-mysql webp-migrator-phpmyadmin webp-migrator-wpcli
podman network rm webp-migrator-net
```

## 🔧 **Troubleshooting**

### **Container Already Exists Error**
```bash
# Use clean start to automatically remove existing containers
./webp-migrator-deploy.sh --clean-start
```

### **Port Already in Use Error**
```bash
# Use different ports
./webp-migrator-deploy.sh --http-port 9080 --mysql-port 3308
```

### **Permission Denied Error**  
```bash
# Make sure scripts are executable
chmod +x *.sh
```

### **Database Connection Error**
```bash
# Wait longer for database to initialize
# The script includes automatic retry logic
```

### **Plugin Not Found Error**
```bash
# Ensure you're running from the correct directory
cd setup
./webp-migrator-deploy.sh
```

## 🎯 **Testing the Plugin**

After deployment, test the WebP Safe Migrator plugin:

1. **📸 Upload Test Images**:
   - Go to Media → Add New
   - Upload JPG, PNG, and GIF images

2. **🔧 Configure Plugin**:
   - Go to Media → WebP Migrator  
   - Set quality to 75 (recommended)
   - Set batch size to 5-10 for testing

3. **▶️ Start Conversion**:
   - Click "Process next batch"
   - Review converted images
   - Check quality and file sizes

4. **✅ Validate Results**:
   - Use validation mode to compare quality
   - Check browser compatibility
   - Test fallback mechanisms

## 🔄 **Development Workflow**

### **Code Changes**:
1. Edit files in `src/` directory  
2. Changes are automatically reflected in container
3. No restart needed for PHP changes

### **Database Changes**:
1. Use phpMyAdmin at `http://localhost:8081`
2. Or use WP-CLI: `podman exec webp-migrator-wpcli wp db ...`

### **WordPress Updates**:
```bash
podman exec webp-migrator-wpcli wp core update --allow-root
podman exec webp-migrator-wpcli wp plugin update --all --allow-root
```

### **Backup/Restore**:
```bash
# Backup (automated in advanced scripts)
podman exec webp-migrator-mysql mysqldump -u root -proot123 wordpress_webp_test > backup.sql

# Restore
cat backup.sql | podman exec -i webp-migrator-mysql mysql -u root -proot123 wordpress_webp_test
```

## 🚀 **Next Steps**

1. **Test the Plugin**: Upload images and test WebP conversion
2. **Customize Settings**: Adjust quality and batch size settings  
3. **Develop Features**: Add new functionality to the plugin
4. **Performance Testing**: Test with large image batches
5. **Browser Testing**: Test WebP support across browsers

## 📞 **Need Help?**

If you encounter issues:

1. Check the container logs:
   ```bash
   podman logs webp-migrator-wordpress
   podman logs webp-migrator-mysql
   ```

2. Run with clean start:
   ```bash
   ./webp-migrator-deploy.sh --clean-start
   ```

3. Use different ports if needed:
   ```bash
   ./webp-migrator-deploy.sh --http-port 9080 --mysql-port 3308
   ```

The new deployment system is designed to handle all common issues automatically and provide a smooth, reliable setup experience.
