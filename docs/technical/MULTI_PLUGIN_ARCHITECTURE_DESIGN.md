# Multi-Plugin Architecture Design Document

**Date:** January 27, 2025  
**Version:** 1.0  
**Status:** Design Phase

## Executive Summary

This document outlines the architectural changes required to transform the WebP Safe Migrator project from a single-plugin system into a multi-plugin development environment. The primary goals are to enable parallel development, testing, and selective deployment of multiple WordPress plugins while maintaining all existing functionality.

## Table of Contents

1. [Current State Analysis](#current-state-analysis)
2. [Requirements Analysis](#requirements-analysis)
3. [Proposed Architecture](#proposed-architecture)
4. [Migration Strategy](#migration-strategy)
5. [Implementation Plan](#implementation-plan)
6. [Configuration System](#configuration-system)
7. [Deployment Changes](#deployment-changes)
8. [Testing Strategy](#testing-strategy)
9. [Risk Assessment](#risk-assessment)
10. [Timeline and Milestones](#timeline-and-milestones)

## Current State Analysis

### Current Plugin Structure
```
src/
├── webp-safe-migrator.php     # Main plugin file
└── uninstall.php              # Uninstall script

includes/                       # Extended classes
├── class-webp-migrator-converter.php
├── class-webp-migrator-logger.php
└── class-webp-migrator-queue.php

admin/                         # Admin assets
├── css/admin.css
└── js/admin.js
```

### Current Deployment Process
- Single plugin hardcoded in scripts
- Plugin slug: `webp-safe-migrator`
- Direct file copying from `src/` to WordPress plugins directory
- No plugin selection mechanism
- Ownership commands run for each deployment

### Current Configuration
- YAML-based configuration in `bin/config/` and `setup/configs/`
- Single plugin configuration section
- No multi-plugin support

## Requirements Analysis

### Functional Requirements

1. **FR1: Plugin Separation**
   - Each plugin must reside in its own subdirectory under `src/`
   - Plugin directories must be self-contained
   - Support for unlimited number of plugins

2. **FR2: Selective Deployment**
   - Configuration-driven plugin selection for deployment
   - Independent plugin activation/deactivation
   - Support for development vs production plugin sets

3. **FR3: Plugin Renaming**
   - Rename existing plugin from `webp-safe-migrator` to `okvir-image-safe-migrator`
   - Update all references in code, scripts, and documentation
   - Maintain backward compatibility during migration

4. **FR4: Admin Interface Updates**
   - Update plugin metadata (name, description, author)
   - Update admin interface text and branding
   - Maintain existing functionality

5. **FR5: Cross-Platform Compatibility**
   - Support Windows PowerShell scripts
   - Support Linux/macOS bash scripts
   - Support Docker/Podman deployment

### Non-Functional Requirements

1. **NFR1: Performance**
   - Deployment time should not significantly increase
   - Plugin loading should remain efficient

2. **NFR2: Maintainability**
   - Clear separation of concerns
   - Consistent naming conventions
   - Comprehensive documentation

3. **NFR3: Reliability**
   - Atomic deployments (all or nothing)
   - Proper error handling and rollback
   - Configuration validation

## Proposed Architecture

### New Directory Structure

```
src/
├── okvir-image-safe-migrator/           # Renamed primary plugin
│   ├── okvir-image-safe-migrator.php    # Main plugin file
│   ├── uninstall.php                    # Uninstall script
│   ├── admin/                           # Plugin-specific admin assets
│   │   ├── css/
│   │   └── js/
│   ├── includes/                        # Plugin-specific classes
│   │   ├── class-image-migrator-converter.php
│   │   ├── class-image-migrator-logger.php
│   │   └── class-image-migrator-queue.php
│   └── readme.txt                       # WordPress plugin readme
│
├── example-second-plugin/               # Example second plugin
│   ├── example-second-plugin.php
│   └── uninstall.php
│
└── shared/                             # Shared utilities (optional)
    ├── common-functions.php
    └── base-classes/
```

### Configuration Architecture

#### Plugin Configuration Schema
```yaml
plugins:
  # Development plugins available
  available:
    - slug: "okvir-image-safe-migrator"
      path: "okvir-image-safe-migrator"
      name: "Okvir Image Safe Migrator"
      main_file: "okvir-image-safe-migrator.php"
      version: "1.0.0"
      
    - slug: "example-second-plugin"
      path: "example-second-plugin"
      name: "Example Second Plugin"
      main_file: "example-second-plugin.php"
      version: "0.1.0"

  # Deployment configuration
  deployment:
    # Plugins to deploy in development environment
    development:
      - slug: "okvir-image-safe-migrator"
        activate: true
        
      - slug: "example-second-plugin"
        activate: false

    # Plugins to deploy in production environment
    production:
      - slug: "okvir-image-safe-migrator"
        activate: true

  # Plugin-specific configurations
  config:
    okvir_image_safe_migrator:
      dev_mode: true
      log_level: "debug"
      quality: 75
      batch_size: 10
```

### Plugin Manager Architecture

#### New Plugin Manager Interface
```bash
# Multi-plugin operations
./setup/plugin-manager.sh install-all --environment=development
./setup/plugin-manager.sh install --plugin=okvir-image-safe-migrator
./setup/plugin-manager.sh activate --plugin=okvir-image-safe-migrator
./setup/plugin-manager.sh list-available
./setup/plugin-manager.sh status-all

# PowerShell equivalent
.\setup\plugin-manager.ps1 install-all -Environment development
.\setup\plugin-manager.ps1 install -Plugin okvir-image-safe-migrator
```

## Migration Strategy

### Phase 1: Plugin Renaming and Restructuring
1. Create new directory structure
2. Move and rename plugin files
3. Update plugin metadata and class names
4. Update admin interface
5. Test single plugin functionality

### Phase 2: Multi-Plugin Infrastructure
1. Implement configuration system
2. Update plugin manager scripts
3. Create plugin discovery mechanism
4. Implement selective deployment
5. Test with multiple plugins

### Phase 3: Deployment Script Updates
1. Update all launcher scripts
2. Update Docker configurations
3. Update environment setup scripts
4. Optimize ownership handling
5. Test all deployment scenarios

### Phase 4: Documentation and Testing
1. Update all documentation
2. Create migration guides
3. Comprehensive testing
4. Performance validation

## Implementation Plan

### Configuration System Changes

#### New Configuration Files
```
bin/config/
├── plugins.yaml              # Plugin definitions and deployment config
├── webp-migrator.config.yaml # Updated main config
└── deployment-profiles.yaml  # Environment-specific deployment profiles

setup/configs/
├── plugins-example.yaml      # Example plugin configurations
└── multi-plugin-config.yaml  # Multi-plugin deployment examples
```

#### Plugin Discovery System
- Automatic plugin discovery in `src/` subdirectories
- Validation of plugin structure and metadata
- Dynamic configuration generation

### Deployment Changes

#### Script Updates Required
1. **Windows Scripts:**
   - `setup/plugin-manager.ps1` - Multi-plugin support
   - `setup/install-wordpress.ps1` - Updated plugin deployment
   - `setup/quick-install.ps1` - Multi-plugin quick setup
   - All launcher scripts in `bin/launch/`

2. **Linux/macOS Scripts:**
   - `setup/plugin-manager.sh` - Multi-plugin support
   - `setup/install-wordpress.sh` - Updated plugin deployment
   - `setup/setup.sh` - Multi-plugin setup
   - All launcher scripts in `bin/launch/`

3. **Docker/Container Scripts:**
   - Update Dockerfiles to handle multi-plugin structure
   - Update docker-compose configurations
   - Update container entry points

#### Ownership Optimization
- Move ownership commands to deployment initialization
- Execute once per deployment, not per plugin
- Separate ownership from plugin installation

## Testing Strategy

### Test Scenarios

1. **Single Plugin Deployment**
   - Deploy only renamed plugin
   - Verify all functionality intact
   - Test activation/deactivation

2. **Multi-Plugin Deployment**
   - Deploy multiple plugins simultaneously
   - Test selective activation
   - Verify no conflicts

3. **Configuration Testing**
   - Test various deployment profiles
   - Validate configuration parsing
   - Test error handling

4. **Migration Testing**
   - Test migration from old to new structure
   - Verify data preservation
   - Test rollback procedures

5. **Cross-Platform Testing**
   - Test on Windows with PowerShell
   - Test on Linux/macOS with bash
   - Test Docker/Podman deployments

### Automated Testing

```bash
# Test script structure
tests/
├── integration/
│   ├── test-multi-plugin-deployment.php
│   ├── test-plugin-activation.php
│   └── test-configuration-parsing.php
├── unit/
│   ├── test-plugin-discovery.php
│   └── test-deployment-scripts.php
└── system/
    ├── test-cross-platform.sh
    └── test-container-deployment.sh
```

## Risk Assessment

### High-Risk Areas
1. **Plugin Renaming** - Potential for broken references
2. **Configuration Changes** - Risk of deployment failures
3. **Script Updates** - Cross-platform compatibility issues

### Mitigation Strategies
1. **Comprehensive Testing** - Test all scenarios before deployment
2. **Incremental Migration** - Phase implementation to reduce risk
3. **Backup Systems** - Maintain rollback capabilities
4. **Documentation** - Clear migration and troubleshooting guides

## Timeline and Milestones

### Milestone 1: Design and Planning (Current)
- [x] Requirements analysis
- [x] Architecture design
- [x] Documentation creation
- [ ] Stakeholder review

### Milestone 2: Plugin Renaming (Week 1)
- [ ] Create new directory structure
- [ ] Move and rename plugin files
- [ ] Update plugin metadata
- [ ] Update admin interface
- [ ] Test single plugin functionality

### Milestone 3: Multi-Plugin Infrastructure (Week 1-2)
- [ ] Implement configuration system
- [ ] Create plugin discovery mechanism
- [ ] Update plugin manager scripts
- [ ] Implement selective deployment
- [ ] Test with multiple plugins

### Milestone 4: Deployment Updates (Week 2)
- [ ] Update all launcher scripts
- [ ] Update Docker configurations
- [ ] Optimize ownership handling
- [ ] Test all deployment scenarios

### Milestone 5: Documentation and Validation (Week 2-3)
- [ ] Update all documentation
- [ ] Create migration guides
- [ ] Comprehensive testing
- [ ] Performance validation

## Success Criteria

1. **Functional Success:**
   - All existing functionality preserved
   - Multi-plugin deployment working
   - Configuration-driven activation
   - Cross-platform compatibility maintained

2. **Technical Success:**
   - Clean, maintainable code structure
   - Comprehensive test coverage
   - Performance maintained or improved
   - Proper error handling and logging

3. **User Experience Success:**
   - Clear documentation and guides
   - Easy migration path
   - Intuitive configuration
   - Reliable deployments

## Conclusion

This multi-plugin architecture represents a significant enhancement to the development capabilities of the WordPress plugin development environment. The proposed changes will enable:

- Parallel development of multiple plugins
- Flexible deployment configurations
- Improved code organization and maintainability
- Enhanced testing and validation capabilities

The phased implementation approach minimizes risk while ensuring all existing functionality is preserved and enhanced.

---

**Next Steps:**
1. Review and approve this design document
2. Begin Phase 1 implementation (Plugin Renaming and Restructuring)
3. Create detailed implementation tasks for each milestone
4. Set up testing environments for validation

**Contact:**
- Technical questions: Review implementation details in each phase
- Progress updates: Monitor todo list and milestone completion
