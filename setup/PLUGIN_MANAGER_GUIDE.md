# WebP Safe Migrator - Plugin Manager Guide

## Overview

The enhanced plugin manager provides complete lifecycle management for the WebP Safe Migrator plugin with database operations, API support, and optional WP-CLI integration.

## Features Added

### ✅ **Database Operations**
- **Setup**: Initialize plugin options with defaults
- **Cleanup**: Remove all plugin data from database
- **Backup**: Include database data in backups
- **Validation**: Verify WordPress tables exist

### ✅ **WP-CLI Integration** (Optional)
- **Auto-installation**: Downloads WP-CLI if needed
- **Plugin activation/deactivation**: Via WordPress API
- **Database queries**: Preferred method when available
- **Fallback support**: Works without WP-CLI

### ✅ **API Support**
- **REST endpoints**: Create API configuration
- **Status endpoint**: `/wp-json/webp-migrator/v1/status`
- **Process endpoint**: `/wp-json/webp-migrator/v1/process`
- **Permission callbacks**: Proper security

### ✅ **Complete Uninstall**
- **File cleanup**: Remove all plugin files
- **Database cleanup**: Remove options and postmeta
- **Backup removal**: Clean up backup directories
- **Hook cleanup**: Clear scheduled cron jobs

### ✅ **Auto-activation**
- **Automatic activation**: After installation
- **WP-CLI powered**: Uses WordPress API
- **Error handling**: Graceful fallback

## Usage Examples

### Basic Operations

```powershell
# Install plugin (basic)
.\setup\plugin-manager.ps1 install

# Install with auto-activation (requires WP-CLI)
.\setup\plugin-manager.ps1 install -UseWPCLI -AutoActivate

# Install with database setup
.\setup\plugin-manager.ps1 install -WithDatabase

# Install with everything
.\setup\plugin-manager.ps1 install -UseWPCLI -AutoActivate -WithDatabase -SetupAPI
```

### Database Operations

```powershell
# Setup database only
.\setup\plugin-manager.ps1 setup-db -WithDatabase

# Clean database only
.\setup\plugin-manager.ps1 cleanup -WithDatabase

# Check status with database info
.\setup\plugin-manager.ps1 status -WithDatabase
```

### Plugin Lifecycle

```powershell
# Activate plugin
.\setup\plugin-manager.ps1 activate -UseWPCLI

# Deactivate plugin
.\setup\plugin-manager.ps1 deactivate -UseWPCLI

# Update plugin (preserves config)
.\setup\plugin-manager.ps1 update -WithDatabase

# Complete uninstall
.\setup\plugin-manager.ps1 uninstall -WithDatabase -UseWPCLI

# Force uninstall (no prompts)
.\setup\plugin-manager.ps1 uninstall -Force -WithDatabase
```

### Backup Operations

```powershell
# Create backup (files + database)
.\setup\plugin-manager.ps1 backup -WithDatabase

# Restore from backup
.\setup\plugin-manager.ps1 restore
```

## Parameters Reference

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `Action` | String | Required | install, update, uninstall, backup, restore, activate, deactivate, status, cleanup, setup-db |
| `WordPressPath` | String | `C:\webp-migrator-test\wordpress` | Path to WordPress installation |
| `SourcePath` | String | `.\src` | Path to plugin source files |
| `BackupPath` | String | `.\backups` | Path for backup storage |
| `Force` | Switch | `$false` | Skip confirmation prompts |
| `UseWPCLI` | Switch | `$false` | Enable WP-CLI operations |
| `AutoActivate` | Switch | `$true` | Auto-activate after install |
| `WithDatabase` | Switch | `$true` | Enable database operations |
| `SetupAPI` | Switch | `$false` | Create API configuration |

## Database Operations Details

### Setup Database (`setup-db`)
- Verifies WordPress tables exist
- Creates default plugin options:
  - `webp_safe_migrator_settings`: Plugin configuration
  - Quality: 59, Batch size: 10, Validation: enabled

### Cleanup Database (`cleanup`)
- Removes all plugin options (`webp_%`)
- Removes all plugin postmeta (`_webp_%`)
- Clears queue and progress options
- Removes scheduled cron jobs

### Database Backup
- Exports all plugin options
- Exports all plugin postmeta
- Saves as JSON in backup directory
- Includes timestamp for tracking

## WP-CLI Integration

### Auto-Installation
- Downloads latest WP-CLI if not present
- Creates batch wrapper for easy execution
- Configures proper working directory

### Fallback Behavior
- Direct MySQL connection when WP-CLI unavailable
- Searches common MySQL installation paths
- Uses WordPress config for connection details
- Graceful degradation for unsupported operations

## API Configuration

When `-SetupAPI` is used, creates `api-config.php` with:

```php
// Status endpoint
GET /wp-json/webp-migrator/v1/status

// Process endpoint  
POST /wp-json/webp-migrator/v1/process
```

Both endpoints require `manage_options` capability.

## Error Handling

### Robust Error Recovery
- Detailed error messages with stack traces
- Graceful fallbacks when services unavailable
- Backup creation before destructive operations
- Validation before critical operations

### Common Issues & Solutions

| Issue | Cause | Solution |
|-------|-------|----------|
| "WordPress installation not found" | Wrong path | Check `WordPressPath` parameter |
| "WP-CLI not available" | No WP-CLI installed | Use `-UseWPCLI` to auto-install |
| "Database query failed" | No MySQL access | Install WP-CLI or check MySQL path |
| "Plugin activation failed" | Plugin issues | Check WordPress error logs |

## Status Information

The `status` action provides comprehensive information:

```
=== Plugin Information ===
Status: Installed
Location: C:\webp-migrator-test\wordpress\wp-content\plugins\webp-safe-migrator
Version: 0.2.0
Active: Yes
Size: 45.2 KB
Files: 8

Database Status:
Connected: Yes
Clean: No

WP-CLI Available: Yes

Available Backups: 3
  webp-safe-migrator-20250127-143022
  webp-safe-migrator-pre-update-20250127-142015
  webp-safe-migrator-pre-uninstall-20250127-141203
```

## Best Practices

### Development Workflow
1. **Install with full features**: `-UseWPCLI -WithDatabase -SetupAPI`
2. **Test changes**: Use `update` action
3. **Create backups**: Before major changes
4. **Clean uninstall**: Use `-WithDatabase -UseWPCLI` for complete removal

### Production Deployment
1. **Test in staging**: Verify all operations work
2. **Create backups**: Before any changes
3. **Monitor logs**: Check for errors after operations
4. **Verify activation**: Ensure plugin works after installation

### Troubleshooting
1. **Use status**: Check current state
2. **Enable verbose**: Add `-Verbose` for detailed output
3. **Check logs**: WordPress debug logs for plugin issues
4. **Manual fallback**: Use WordPress admin if automation fails
