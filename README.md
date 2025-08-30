# WebP Safe Migrator

Convert all non-WebP images in your WordPress media library to WebP at a fixed quality, safely update all usages and metadata, then (optionally) remove originals after validation. Includes WP-CLI, skip rules, change reports, and advanced processing options.

## 📋 Table of Contents

- [Quick Start](#quick-start)
- [Features](#features)
- [Installation](#installation)
- [Usage](#usage)
- [Configuration](#configuration)
- [WP-CLI](#wp-cli)
- [Documentation](#documentation)
- [Development](#development)

## 🚀 Quick Start

### **Option 1: One-Command Launch (Recommended)**
```bash
# Windows
.\launch-webp-migrator.bat

# Linux/macOS
./launch-webp-migrator.sh

# Universal (auto-detects platform)
./launch-universal.sh
```
**Result**: Complete WordPress + Plugin environment ready in 2-3 minutes at http://localhost:8080

### **Option 2: Manual Installation**
1. **Install Plugin**: Create `wp-content/plugins/webp-safe-migrator/` and copy plugin files
2. **Activate**: Go to **WP Admin → Plugins** and activate
3. **Configure**: Visit **Media → WebP Migrator** to set quality, batch size, and validation mode
4. **Process**: Click **Process next batch** to start conversion
5. **Validate**: Review converted images on your site
6. **Commit**: Use **Commit** buttons to permanently delete originals

## ✨ Features

### Core Functionality
- ✅ **WebP Conversion** - Convert JPEG/PNG/GIF to WebP at configurable quality
- ✅ **Database Updates** - Safe search & replace across posts, postmeta, options (including serialized data)
- ✅ **Metadata Management** - Complete attachment metadata updates
- ✅ **Validation Mode** - Two-phase backup/commit workflow for safety
- ✅ **Batch Processing** - Configurable batch sizes with progress tracking
- ✅ **Skip Rules** - Exclude specific folders and MIME types
- ✅ **Comprehensive Reports** - Detailed change tracking per attachment

### Advanced Features
- 🔄 **Background Processing** - Async job queue for large libraries
- 🎛️ **Advanced Options** - Size constraints, quality presets, selective transformations
- 📊 **Real-time Progress** - Live progress bars and status updates
- 🔍 **Enhanced Validation** - Comprehensive file and metadata verification
- 📝 **Comprehensive Logging** - Multi-level logging with export capabilities
- 🖥️ **Modern Admin UI** - Responsive interface with visual previews

### Technical Features
- 🛡️ **Security** - Proper nonces, capability checks, input sanitization
- ⚡ **Performance** - Memory-conscious processing, optimized database queries
- 🧪 **Testing** - Complete unit and integration test suite
- 🔧 **WP-CLI Support** - Command-line automation for developers
- 📱 **Responsive Design** - Mobile-friendly admin interface

## 📦 Installation

### Method 1: Manual Installation
1. Download or clone this repository
2. Copy `src/webp-safe-migrator.php` to `wp-content/plugins/webp-safe-migrator/webp-safe-migrator.php`
3. Activate in **WP Admin → Plugins**

### Method 2: Development Setup

#### Windows (PowerShell)

#### Option A: Fully Automated (Recommended)
Complete WordPress setup with zero manual intervention:
```powershell
# One-command setup - creates everything automatically
.\setup\quick-install.ps1

# Opens WordPress at http://localhost:8080 with:
# - Username: admin
# - Password: admin123
# - Plugin pre-installed and activated
```

#### Option B: Semi-Automated
WAMP stack + WordPress download, requires manual WordPress setup:
```powershell
# Downloads and configures WAMP + WordPress
.\setup\install-wordpress.ps1

# Then complete WordPress installation at http://localhost:8080
# Then install plugin
.\setup\plugin-manager.ps1 install
```

#### Option C: Enhanced Plugin Management
Complete plugin lifecycle management with database operations:
```powershell
# Install with auto-activation and database setup
.\setup\plugin-manager.ps1 install -UseWPCLI -AutoActivate -WithDatabase

# Update plugin preserving configuration
.\setup\plugin-manager.ps1 update -WithDatabase

# Complete uninstall with database cleanup
.\setup\plugin-manager.ps1 uninstall -WithDatabase -UseWPCLI

# Check plugin status
.\setup\plugin-manager.ps1 status -WithDatabase
```

See **[Plugin Manager Guide](setup/PLUGIN_MANAGER_GUIDE.md)** for complete documentation.

#### Linux/macOS (Bash)

##### Option A: Universal Setup (Recommended)
Automatically detects your system and chooses the best installation method:
```bash
# Interactive setup - detects Docker, package managers, etc.
./setup/setup.sh

# The script will guide you through:
# - Docker setup (if available)
# - Native installation (if admin privileges)
# - Manual setup (for existing WordPress)
```

##### Option B: One-Command Setup
Complete WordPress setup with zero manual intervention:
```bash
# Quick setup with defaults
./setup/quick-install.sh

# Custom configuration
./setup/quick-install.sh --install-path ~/my-webp-test

# Docker-based setup
./setup/quick-install.sh --use-docker
```

##### Option C: Detailed Control
Full control over installation process:
```bash
# Native LAMP/LEMP installation
./setup/install-wordpress.sh --start-services

# Fully automated with WordPress setup
./setup/install-wordpress-automated.sh

# Plugin management
./setup/plugin-manager.sh install --use-wpcli --setup-api
```

##### Option D: Container Development
Container-based development environment (Docker/Podman):
```bash
# Universal container setup (auto-detects Podman/Docker)
./setup/container-setup.sh up

# Or use specific container engine:
# Podman (recommended - no licensing restrictions)
./setup/podman-setup.sh up

# Docker (commercial licensing may apply)
./setup/docker-setup.sh up

# Install WordPress and plugin
./setup/container-setup.sh install

# Use WP-CLI
./setup/container-setup.sh wp plugin list
```

See **[Bash Scripts Guide](setup/BASH_SCRIPTS_GUIDE.md)** for complete documentation.

## 🎮 Usage

### Dashboard Interface
1. Navigate to **Media → WebP Migrator**
2. Configure settings:
   - **Quality**: 1-100 (recommended: 59-85)
   - **Batch Size**: Number of images per batch (default: 10)
   - **Validation Mode**: Keep originals until commit (recommended)
   - **Skip Rules**: Exclude folders/MIME types
3. Click **Process next batch**
4. Monitor progress and review reports
5. **Commit deletions** when satisfied

### Background Processing
For large media libraries:
1. Select attachments to process
2. Choose **Start Background Processing**
3. Monitor real-time progress
4. Continue working while processing runs in background

## ⚙️ Configuration

### Quality Settings
- **High Quality**: 85-95 (larger files, better quality)
- **Balanced**: 65-80 (good quality/size ratio)
- **High Compression**: 45-65 (smaller files, lower quality)

### Skip Rules
- **Folders**: One per line, relative to uploads directory
- **MIME Types**: Comma-separated (e.g., `image/gif, image/png`)

### Advanced Options
- **Size Constraints**: Maximum width/height limits
- **Conversion Mode**: Quality only, resize only, or both
- **Preserve Dimensions**: Maintain original image dimensions

## 🖥️ WP-CLI

### Basic Commands
```bash
# Process batch with validation (keeps originals)
wp webp-migrator run --batch=100

# Process without validation (deletes originals immediately)
wp webp-migrator run --batch=100 --no-validate

# Custom batch size
wp webp-migrator run --batch=50
```

### Advanced Usage
```bash
# Background processing via CLI
wp webp-migrator run --batch=500 --background

# Check processing status
wp webp-migrator status

# View conversion statistics
wp webp-migrator stats
```

## 📚 Documentation

### Quick Operations
- **[🚀 Launcher Scripts Guide](documentation/LAUNCHER_SCRIPTS.md)** - **← EASIEST START** - Simple deployment scripts
- **[🎛️ Operations Index](setup/OPERATIONS_INDEX.md)** - Complete task navigation  
- **[🎯 Command Cheat Sheet](setup/COMMAND_CHEAT_SHEET.md)** - All commands reference for daily use
- **[⚙️ Configuration Examples](setup/CONFIG_EXAMPLES.md)** - Customize usernames, passwords, domains, ports
- **[🛑 Graceful Shutdown Guide](setup/GRACEFUL_SHUTDOWN.md)** - Proper shutdown procedures
- **[🚀 Quick Start Guide](setup/QUICK_START.md)** - Complete setup walkthrough

### Core Documentation
- **[📖 Documentation Index](documentation/INDEX.md)** - Complete documentation navigation
- **[📋 Requirements Analysis](documentation/REQUIREMENTS_ANALYSIS.md)** - Requirements satisfaction and traceability
- **[🏗️ Architecture Guide](documentation/ARCHITECTURE.md)** - Technical architecture and design
- **[📊 Implementation Review](documentation/COMPREHENSIVE_REVIEW_SUMMARY.md)** - Code review and roadmap

### Visual References
- **[📋 System Diagrams](documentation/diagrams/)** - Architecture and flow diagrams
  - [High-Level Architecture](documentation/diagrams/high-level.svg)
  - [Data Flow Process](documentation/diagrams/data-flow.svg)
  - [Database Rewriting](documentation/diagrams/db-rewrite.svg)
  - [Batch Processing Sequence](documentation/diagrams/sequence-batch.svg)

### Development Resources
- **[🔧 Setup Scripts](setup/)** - Local development environment
- **[🧪 Test Suite](tests/)** - Comprehensive testing framework
- **[💾 Source Code](src/)** - Plugin implementation files

## 🛠️ Development

### Local Environment Setup
```bash
# Quick start (Windows)
.\launch-webp-migrator.bat

# Quick start (Linux/macOS)
./launch-webp-migrator.sh
```

### Development Workflow
```bash
1. Launch environment (once per session)
2. Edit files in src/ directory  
3. Refresh WordPress admin - changes are live!
4. No container restart needed for code changes
```

**Key**: The `src/` directory is **volume-mounted** for instant code changes!

### Testing
```bash
# Run unit tests
phpunit tests/unit/

# Run integration tests
phpunit tests/integration/

# Run all tests
phpunit
```

### Code Quality
- **PSR-12** coding standards
- **WordPress** coding standards compliance
- **PHPUnit** test coverage
- **Security** best practices

## 📋 Requirements

### System Requirements
- **WordPress**: 5.8+ (recommended: 6.x)
- **PHP**: 7.4+ (recommended: 8.1+)
- **Memory**: 256MB+ (512MB+ for large libraries)
- **WebP Support**: GD with `imagewebp()` or Imagick with WebP format

### Server Requirements
- File system write permissions to `wp-content/uploads/`
- Adequate execution time limits for batch processing
- Sufficient disk space for temporary backups

## 🚨 Important Notes

### Safety Features
- **Validation Mode**: Default setting keeps originals until you commit
- **Comprehensive Backups**: All originals safely stored before deletion
- **Detailed Reports**: Track every change made during conversion
- **Rollback Support**: Restore from backups if needed

### Performance Considerations
- Start with small batch sizes (10-25 images)
- Use background processing for large libraries (1000+ images)
- Monitor server resources during processing
- Clear caches after conversion

## 🤝 Support & Contributing

### Getting Help
1. Check the **[Documentation](documentation/INDEX.md)** for detailed guides
2. Review **[Architecture](documentation/ARCHITECTURE.md)** for technical details
3. Examine **[Implementation Review](documentation/COMPREHENSIVE_REVIEW_SUMMARY.md)** for known issues

### Contributing
1. Fork the repository
2. Create a feature branch
3. Run the test suite
4. Submit a pull request with detailed description

## 📄 License

GPL-2.0+ (GNU General Public License v2.0 or later) - Compatible with WordPress licensing.

---

**🔗 Quick Navigation:**
[🚀 Launcher](documentation/LAUNCHER_SCRIPTS.md) | 
[🎛️ Operations](setup/OPERATIONS_INDEX.md) | 
[🎯 Commands](setup/COMMAND_CHEAT_SHEET.md) |
[⚙️ Config](setup/CONFIG_EXAMPLES.md) |
[🛑 Shutdown](setup/GRACEFUL_SHUTDOWN.md) | 
[📖 Full Docs](documentation/INDEX.md) | 
[🧪 Tests](tests/)

**📅 Last Updated:** January 27, 2025 | **📋 Version:** 2.0