# WebP Safe Migrator - Configuration System

This documentation describes the comprehensive configuration system for autonomous WordPress setup.

## 🎯 Overview

The configuration system allows you to:
- Define all setup parameters in YAML files
- Generate all necessary Docker/Podman configurations programmatically
- Achieve fully autonomous installations without manual intervention
- Customize every aspect of the WordPress development environment

## 📁 Files Structure

```
setup/
├── webp-migrator-config.yaml      # Complete configuration template
├── simple-config.yaml             # Minimal configuration example
├── config-generator.py            # Python configuration generator
├── generate-config.sh             # Shell wrapper for generator
├── README-CONFIG-SYSTEM.md        # This documentation
└── generated/                     # Generated configuration files
    ├── docker-compose.yml
    ├── .env
    ├── mysql-init/
    │   └── 01-webp-migrator-init.sql
    └── install-automated.sh
```

## 🚀 Quick Start

### 1. Choose Your Configuration Template

**For beginners or simple setups:**
```bash
cp simple-config.yaml my-config.yaml
```

**For advanced setups with full control:**
```bash
cp webp-migrator-config.yaml my-config.yaml
```

### 2. Customize Your Configuration

Edit `my-config.yaml` and modify values as needed:

```yaml
# Example customizations
infrastructure:
  container_engine: "docker"  # or "podman"
  install_path: "~/my-webp-test"

database:
  root_user:
    password: "my-secure-password"  # or "auto" for auto-generation

wordpress:
  site:
    title: "My WebP Test Site"
    url: "https://webp.mycompany.com"
  admin_user:
    username: "myadmin"
    password: "auto"  # Will generate secure password
    email: "admin@mycompany.com"
```

### 3. Generate Configuration Files

**Using the shell wrapper (recommended):**
```bash
./setup/generate-config.sh my-config.yaml
```

**Using Python directly:**
```bash
python3 setup/config-generator.py my-config.yaml -o setup/generated/
```

### 4. Deploy Your Environment

```bash
cd setup/generated/
docker-compose up -d
./install-automated.sh
```

## 🔧 Configuration Sections

### Infrastructure Configuration

```yaml
infrastructure:
  container_engine: "docker"        # docker, podman, auto
  install_path: "~/webp-test"       # Installation directory
  platform_override: "auto"         # linux, macos, windows, wsl, auto
  ports:
    http: 80                        # HTTP port
    https: 443                      # HTTPS port
    mysql: 3306                     # MySQL port
    phpmyadmin: 8081               # phpMyAdmin port
    # Alternative ports for development
    alt_http: 8080
    alt_https: 8443
    alt_mysql: 3307
```

### SSL/TLS Configuration

```yaml
ssl:
  enabled: true
  certificate_type: "self_signed"   # self_signed, letsencrypt, custom
  
  # Self-signed certificate details
  self_signed:
    country: "US"
    common_name: "localhost"
    days_valid: 365
    subject_alt_names:
      - "DNS:localhost"
      - "DNS:webp-migrator.local"
      - "IP:127.0.0.1"
  
  # Let's Encrypt configuration
  letsencrypt:
    email: "admin@example.com"
    domains: ["webp-migrator.example.com"]
    staging: true                   # Use staging for testing
```

### Database Configuration

```yaml
database:
  engine: "mysql"                   # mysql, mariadb, postgresql
  version: "8.0"
  name: "wordpress_webp_test"
  charset: "utf8mb4"
  collation: "utf8mb4_unicode_ci"
  
  # Database users (use "auto" for secure password generation)
  root_user:
    username: "root"
    password: "auto"                # Will generate secure password
  wordpress_user:
    username: "wordpress"
    password: "auto"                # Will generate secure password
  test_user:
    username: "webp_test"
    password: "auto"                # Will generate secure password
```

### WordPress Configuration

```yaml
wordpress:
  version: "latest"                 # latest, 6.4, 6.3, etc.
  site:
    title: "WebP Migrator Test Site"
    url: "http://localhost"
    language: "en_US"               # en_US, fr_FR, de_DE, etc.
    timezone: "UTC"
  
  admin_user:
    username: "admin"
    password: "auto"                # Will generate secure password
    email: "admin@webp-test.local"
    role: "administrator"
  
  # WordPress Security Keys & Salts (leave empty for auto-generation)
  security:
    auth_key: ""                    # Will auto-generate
    secure_auth_key: ""             # Will auto-generate
    # ... all WordPress salts
  
  # WordPress configuration constants
  config:
    debug: true
    debug_log: true
    debug_display: false
    wp_memory_limit: "512M"
    force_ssl_admin: true
```

### Plugin Configuration

```yaml
plugins:
  install:
    # Local plugin (your development plugin)
    - slug: "webp-safe-migrator"
      source: "local"
      local_path: "../src"
      activate: true
    
    # WordPress.org plugin
    - slug: "query-monitor"
      source: "wordpress_org"
      activate: true
    
    # External plugin from URL
    - slug: "custom-plugin"
      source: "zip_url"
      url: "https://example.com/plugin.zip"
      activate: false
  
  # Plugin-specific settings
  webp_migrator:
    dev_mode: true
    log_level: "debug"
    quality: 75
    batch_size: 10
```

## 🤖 Autonomous Setup Features

### Auto-Generated Values

The system automatically generates secure values for:

- **Passwords**: Use `"auto"` to generate 16-character secure passwords
- **WordPress Salts**: Empty values are auto-filled with 64-character salts
- **SSL Certificates**: Self-signed certificates with proper SAN entries
- **Configuration Files**: All Docker, database, and installation files

### Environment-Specific Configurations

```yaml
# Override settings based on environment
environments:
  development:
    wordpress:
      config:
        debug: true
        debug_display: true
        
  production:
    wordpress:
      config:
        debug: false
        debug_display: false
    ssl:
      letsencrypt:
        staging: false
```

### Programmatic Integration

The configuration system is designed for automation:

```python
#!/usr/bin/env python3
from setup.config_generator import ConfigGenerator

# Programmatically create configuration
config = {
    'infrastructure': {
        'container_engine': 'docker',
        'install_path': '/opt/webp-test'
    },
    'wordpress': {
        'admin_user': {
            'username': 'admin',
            'password': 'auto',  # Will generate secure password
            'email': 'admin@mysite.com'
        }
    }
    # ... more configuration
}

# Generate all files
generator = ConfigGenerator(config)
generator.generate_all_configs()
```

## 📋 Complete Configuration Reference

### Available Configuration Sections

1. **infrastructure** - Container engine, paths, ports
2. **ssl** - SSL/TLS certificate configuration
3. **networking** - Domain, network settings
4. **database** - MySQL/MariaDB configuration
5. **wordpress** - WordPress core configuration
6. **php** - PHP settings and extensions
7. **webserver** - Apache/Nginx configuration
8. **plugins** - Plugin installation and settings
9. **services** - Additional services (Redis, phpMyAdmin, etc.)
10. **development** - Development tools and sample data
11. **automation** - Autonomous setup options
12. **maintenance** - Backup and maintenance settings
13. **environments** - Environment-specific overrides

### Security Features

- **Secure Password Generation**: Cryptographically secure random passwords
- **WordPress Salts**: Automatically generated unique salts
- **SSL by Default**: Self-signed certificates with proper configuration
- **Database Security**: Separate users with minimal privileges
- **File Permissions**: Proper ownership and permissions

## 🔍 Examples

### Production-Ready Configuration

```yaml
infrastructure:
  container_engine: "docker"
  ports:
    http: 80
    https: 443

ssl:
  enabled: true
  certificate_type: "letsencrypt"
  letsencrypt:
    email: "admin@mycompany.com"
    domains: ["webp.mycompany.com"]
    staging: false

wordpress:
  site:
    title: "WebP Migration Tool"
    url: "https://webp.mycompany.com"
  admin_user:
    username: "webpadmin"
    password: "auto"
    email: "webp-admin@mycompany.com"

database:
  root_user:
    password: "auto"  # Generates secure password
  wordpress_user:
    password: "auto"  # Generates secure password

automation:
  auto_install_wordpress: true
  skip_confirmations: true
  auto_open_browser: false
```

### Development Configuration

```yaml
infrastructure:
  container_engine: "podman"  # License-free alternative
  ports:
    http: 8080              # Non-privileged ports
    https: 8443
    mysql: 3307

wordpress:
  config:
    debug: true
    debug_display: true     # Show errors for development
    
development:
  sample_data:
    install_sample_content: true
    install_test_images: true
  tools:
    wp_cli: true
    composer: true
    
plugins:
  install:
    - slug: "query-monitor"
      source: "wordpress_org"
      activate: true
    - slug: "debug-bar"
      source: "wordpress_org"
      activate: true
```

## 🛠️ Advanced Usage

### Custom Docker Images

The generated Dockerfile supports build arguments from your configuration:

```yaml
php:
  version: "8.2"

wordpress:
  version: "6.4"
```

Generates:
```dockerfile
ARG WORDPRESS_VERSION=6.4
ARG PHP_VERSION=8.2
FROM wordpress:${WORDPRESS_VERSION}
```

### Multiple Environment Configurations

```bash
# Development environment
./generate-config.sh dev-config.yaml -o dev/

# Staging environment  
./generate-config.sh staging-config.yaml -o staging/

# Production environment
./generate-config.sh prod-config.yaml -o production/
```

### CI/CD Integration

```yaml
# In your CI/CD pipeline
automation:
  skip_confirmations: true
  auto_install_wordpress: true
  auto_open_browser: false
  wait_for_services: true
  max_wait_time: 600
```

## ❓ FAQ

**Q: Can I use this system without Docker?**
A: Currently, the system is designed for containerized environments (Docker/Podman), but the configuration structure could be extended to support native installations.

**Q: How secure are auto-generated passwords?**
A: Passwords use Python's `secrets` module with cryptographically secure random generation, creating 16-character passwords with mixed alphanumeric and special characters.

**Q: Can I add custom plugins not in the WordPress repository?**
A: Yes, use the `zip_url` source type to install plugins from any URL, or `local` for plugins in development.

**Q: Is the configuration backward compatible?**
A: The configuration system is designed to be forward-compatible. Older configuration files will continue to work, with sensible defaults for new options.

**Q: Can I use this for production deployments?**
A: While the system can generate production-ready configurations, review all security settings and consider using proper SSL certificates (Let's Encrypt) for production use.

## 🔗 Related Files

- `webp-migrator-config.yaml` - Complete configuration template with all options
- `simple-config.yaml` - Minimal configuration for quick setup
- `config-generator.py` - Python script that generates all configuration files
- `generate-config.sh` - Shell wrapper for easy configuration generation
