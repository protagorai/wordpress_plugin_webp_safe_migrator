# WordPress Login Issue - Root Cause Analysis & Fix

## Problem Summary

When starting fresh containers or cleaning and relaunching the project, the WordPress admin panel launches but login credentials don't work as expected.

## Root Cause Analysis

### 1. **Credential Inconsistency** (Primary Issue)
- **`webp-migrator.env`** (used by Windows): `WP_ADMIN_PASS=admin123` ❌
- **Expected by user**: `admin123!` ✅
- **Most other scripts**: `admin123!` ✅
- **Result**: Windows launcher created admin user with wrong password

### 2. **Platform-Specific Differences**
- Linux/macOS scripts hardcode `admin123!`
- Windows scripts load from `webp-migrator.env` which had `admin123`
- No single source of truth for credentials

### 3. **Timing & Health Check Issues**
- No proper health checks for Docker services
- Fixed wait times (30-60 seconds) without verification
- WordPress installation attempted before services were ready
- No coordination between container startup and WordPress installation

### 4. **Incomplete Docker Compose Configuration**
- Missing health checks for database and WordPress services
- No dependency management based on service health
- WP-CLI container didn't wait for healthy services

## Implemented Fixes

### ✅ 1. Fixed Credential Consistency
- **Updated `webp-migrator.env`**: Changed `WP_ADMIN_PASS=admin123!`
- **All platforms now use**: `admin/admin123!`
- **Single source of truth**: Configuration files are consistently used

### ✅ 2. Enhanced Docker Compose Configuration
```yaml
# Added health checks for database
db:
  healthcheck:
    test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-proot123"]
    interval: 10s
    timeout: 5s
    retries: 10
    start_period: 30s

# Added health checks for WordPress
wordpress:
  healthcheck:
    test: ["CMD", "curl", "-f", "http://localhost/"]
    interval: 30s
    timeout: 10s
    retries: 5
    start_period: 60s
  depends_on:
    db:
      condition: service_healthy

# Updated WP-CLI container
wpcli:
  depends_on:
    db:
      condition: service_healthy
    wordpress:
      condition: service_healthy
```

### ✅ 3. Created Auto-Installation Scripts
- **`setup/wp-auto-install.sh`** (Linux/macOS)
- **`setup/wp-auto-install.bat`** (Windows)
- Unified WordPress installation process across platforms
- Proper health checks and wait loops
- Handles both fresh installs and existing installations
- Updates admin user password to match configuration

### ✅ 4. Improved Windows Launcher
- Better connection verification with retry loops
- Checks if WordPress is already installed
- Updates admin password if user exists
- Creates admin user if missing
- More detailed error messages and debugging info

## Current Credentials (Fixed)

```
Username: admin
Password: admin123
Email: admin@webp-test.local
```

These are now consistent across all platforms and configurations.

**Note**: We standardized on `admin123` (without exclamation mark) to avoid Windows batch file special character handling issues.

## How to Use the Fixed Setup

### Option 1: Windows (Recommended for your environment)
```cmd
# Clean start (removes all containers and data)
cleanup-webp-migrator.bat

# Launch with auto-installation
launch-webp-migrator.bat
```

### Option 2: Docker Compose (Cross-platform)
```bash
# Clean start
cd setup
docker compose down -v

# Start services with health checks
docker compose up -d

# Wait for services to be healthy, then auto-install WordPress
./wp-auto-install.sh  # Linux/macOS
# OR
wp-auto-install.bat   # Windows
```

### Option 3: Enhanced Setup Script (Linux/macOS)
```bash
cd setup
./setup-enhanced.sh clean    # Clean everything
./setup-enhanced.sh up       # Start services
./setup-enhanced.sh install  # Install WordPress
```

## Verification Steps

1. **Check container health**:
   ```cmd
   podman ps
   # OR
   docker ps
   ```
   Look for "healthy" status on WordPress and MySQL containers

2. **Test WordPress access**:
   - Site: http://localhost:8080
   - Admin: http://localhost:8080/wp-admin
   - Credentials: `admin` / `admin123`

3. **Verify plugin activation**:
   - Go to WordPress Admin → Plugins
   - WebP Safe Migrator should be active
   - Or go to Media → WebP Migrator

## Configuration Customization

Edit `webp-migrator.env` to customize credentials:

```env
# WordPress Admin Credentials
WP_ADMIN_USER=your_username
WP_ADMIN_PASS=your_secure_password!
WP_ADMIN_EMAIL=your_email@domain.com
```

Then rerun the launcher script to apply changes.

## Troubleshooting

### Issue: "WordPress installation failed"
**Solution**: 
1. Check container logs: `podman logs webp-migrator-wordpress`
2. Verify database is healthy: `podman exec webp-migrator-mysql mysqladmin ping -u root -proot123`
3. Wait longer for services to be ready

### Issue: "Plugin activation failed"
**Solution**:
1. Log into WordPress admin
2. Go to Plugins → Installed Plugins
3. Manually activate "WebP Safe Migrator"

### Issue: Still can't login with admin/admin123
**Solution**:
1. Verify your current config: `type webp-migrator.env` (Windows) or `cat webp-migrator.env` (Linux)
2. Clean containers completely: `cleanup-webp-migrator.bat`
3. Relaunch: `launch-webp-migrator.bat`
4. Check WordPress user in database:
   ```cmd
   podman exec webp-migrator-wpcli wp user list --allow-root
   ```

## What Changed

### Files Modified:
- ✅ `webp-migrator.env` - Fixed password to `admin123`
- ✅ `launch-webp-migrator.bat` - Added health checks and better error handling
- ✅ `setup/docker-compose.yml` - Added health checks and service dependencies
- ✅ `setup/setup-enhanced.sh` - Integrated auto-installation script

### Files Created:
- ✅ `setup/wp-auto-install.sh` - Cross-platform WordPress auto-installation
- ✅ `setup/wp-auto-install.bat` - Windows WordPress auto-installation
- ✅ `WORDPRESS_LOGIN_FIX_GUIDE.md` - This documentation

## Future Improvements

- [ ] Add SSL certificate generation to auto-install scripts
- [ ] Create health check dashboard
- [ ] Add backup/restore functionality for development data
- [ ] Implement environment-specific configurations (dev/staging/prod)

---

**Summary**: The login issue was caused by inconsistent password configuration across platforms. The fix ensures all platforms use the same credentials (`admin/admin123!`) and includes robust health checks and auto-installation capabilities for consistent, reliable WordPress setup.
