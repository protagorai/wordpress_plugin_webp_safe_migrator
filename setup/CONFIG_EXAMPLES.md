# ⚙️ WebP Safe Migrator - Configuration Examples

**Quick examples** for the most common configuration customizations. Copy and modify these examples for your setup.

## 🚀 Quick Start Examples

### **Example 1: Basic Custom Setup**
Copy this to `my-config.yaml` and edit the values:

```yaml
# =============================================================================
# BASIC CUSTOMIZATION - Edit these values for your setup
# =============================================================================
database:
  name: "my_webp_project"              # ← Your database name
  wordpress_user:
    username: "wp_myproject"           # ← Your WordPress DB user
    password: "auto"                   # ← Auto-generates secure password

wordpress:
  site:
    title: "My WebP Migration Site"    # ← Your site title
    url: "http://localhost:9080"       # ← Your custom URL/port
  admin_user:
    username: "myadmin"                # ← Your admin username
    password: "auto"                   # ← Auto-generates secure password
    email: "admin@mycompany.com"       # ← Your email

infrastructure:
  container_engine: "podman"           # ← "podman" (free) or "docker"
  install_path: "~/my-webp-project"    # ← Your installation directory
  ports:
    http: 9080                         # ← Custom HTTP port (avoid conflicts)
    https: 9443                        # ← Custom HTTPS port
    mysql: 3308                        # ← Custom MySQL port

# Everything else uses defaults - add more sections as needed
```

### **Example 2: Production-Ready Setup**
For production or staging environments:

```yaml
# Production configuration
ssl:
  enabled: true
  certificate_type: "letsencrypt"       # ← Real SSL certificates
  letsencrypt:
    email: "admin@mycompany.com"        # ← Your email for Let's Encrypt
    domains: ["webp.mycompany.com"]     # ← Your domain
    staging: false                      # ← Use production Let's Encrypt

wordpress:
  site:
    title: "WebP Migration Tool"
    url: "https://webp.mycompany.com"   # ← Your production domain
  config:
    debug: false                        # ← Disable debugging for production
    debug_display: false

automation:
  auto_open_browser: false             # ← Don't open browser on server
  skip_confirmations: true             # ← For CI/CD automation
```

### **Example 3: Development with Custom Ports**
When you have port conflicts:

```yaml
# Development with non-standard ports
infrastructure:
  ports:
    http: 8090                         # ← Different HTTP port
    https: 8493                        # ← Different HTTPS port  
    mysql: 3309                        # ← Different MySQL port
    phpmyadmin: 8095                   # ← Different phpMyAdmin port

wordpress:
  site:
    url: "http://localhost:8090"       # ← Match the HTTP port above
  config:
    debug: true                        # ← Enable debugging
    debug_display: true                # ← Show errors on screen

development:
  sample_data:
    install_sample_content: true       # ← Create test content
    install_test_images: true          # ← Create test images
```

## 📝 Step-by-Step Configuration

### **Step 1: Copy Template**
```bash
# For basic setup
cp setup/simple-config.yaml my-config.yaml

# For advanced setup (all options)  
cp setup/webp-migrator-config.yaml my-config.yaml
```

### **Step 2: Edit Key Values**
Open `my-config.yaml` and customize these common settings:

```yaml
# 🔐 SECURITY (change these!)
database:
  name: "YOUR_DB_NAME_HERE"
  wordpress_user:
    username: "YOUR_DB_USER_HERE"
    password: "auto"                   # Auto-generates secure password

wordpress:
  admin_user:
    username: "YOUR_ADMIN_USERNAME"    # Don't use "admin"!
    password: "auto"                   # Auto-generates secure password
    email: "YOUR_EMAIL_HERE"

# 🌐 NETWORKING (customize for your environment)
infrastructure:
  install_path: "~/YOUR_PROJECT_PATH"  # Where to install
  ports:
    http: YOUR_HTTP_PORT               # e.g., 8080, 9080
    mysql: YOUR_MYSQL_PORT             # e.g., 3307, 3308

wordpress:
  site:
    title: "YOUR_SITE_TITLE"
    url: "http://localhost:YOUR_HTTP_PORT"
```

### **Step 3: Generate and Deploy**
```bash
# Generate all configuration files
./setup/generate-config.sh my-config.yaml

# Deploy with your custom configuration
cd setup/generated/
docker-compose up -d
./install-automated.sh
```

### **Step 4: Access Your Custom Setup**
```bash
# Your WordPress will be available at:
# http://localhost:YOUR_HTTP_PORT/?auto_login=dev_mode

# Your phpMyAdmin will be at:
# http://localhost:YOUR_PHPMYADMIN_PORT
```

## 🎯 Common Customizations

### **Change Database Credentials**
```yaml
database:
  name: "my_custom_db"
  root_user:
    username: "root"
    password: "my_secure_root_pass"    # Or use "auto"
  wordpress_user:
    username: "my_wp_user"
    password: "my_secure_wp_pass"      # Or use "auto"
```

### **Custom Domain Setup**
```yaml
networking:
  custom_domain: "webp-test.mycompany.local"
  
wordpress:
  site:
    url: "http://webp-test.mycompany.local"

# Don't forget to add to /etc/hosts:
# 127.0.0.1 webp-test.mycompany.local
```

### **Multiple Environments**
```yaml
# Create different configs for each environment
# dev-config.yaml
infrastructure:
  ports:
    http: 8080

# staging-config.yaml  
infrastructure:
  ports:
    http: 8090

# prod-config.yaml
ssl:
  enabled: true
  certificate_type: "letsencrypt"
```

## 🔒 Security Best Practices

### **Use Auto-Generated Passwords**
```yaml
# ✅ GOOD - Auto-generate secure passwords
database:
  wordpress_user:
    password: "auto"                   # Generates secure password

wordpress:
  admin_user:
    password: "auto"                   # Generates secure password

# ❌ AVOID - Hardcoded weak passwords
database:
  wordpress_user:
    password: "123456"                 # Weak and visible in config
```

### **Don't Use Default Usernames**
```yaml
# ✅ GOOD - Custom usernames
wordpress:
  admin_user:
    username: "site_admin"             # Custom admin username

database:
  wordpress_user:
    username: "wp_myproject"           # Custom database user

# ❌ AVOID - Default usernames
wordpress:
  admin_user:
    username: "admin"                  # Predictable username

database:
  wordpress_user:
    username: "wordpress"              # Default username
```

## 🔧 Configuration Validation

### **Test Your Configuration**
```bash
# Check YAML syntax
python3 -c "import yaml; print('✅ Valid YAML' if yaml.safe_load(open('my-config.yaml')) else '❌ Invalid YAML')"

# Validate with config generator
python3 setup/config-generator.py my-config.yaml --validate-only

# Test Docker Compose generation
./setup/generate-config.sh my-config.yaml -o test/
cd test/ && docker-compose config
```

### **Common Configuration Errors**
```yaml
# ❌ WRONG - Invalid YAML syntax
database
  name "my_db"                         # Missing colon and quotes

# ✅ CORRECT - Valid YAML syntax  
database:
  name: "my_db"

# ❌ WRONG - Port conflicts
infrastructure:
  ports:
    http: 80                           # May conflict with system
    mysql: 3306                        # May conflict with system

# ✅ CORRECT - Non-privileged ports
infrastructure:
  ports:
    http: 8080                         # Safe port
    mysql: 3307                        # Safe port
```

## 🚀 Quick Deployment Commands

### **One-Command Custom Deployment**
```bash
# Create, configure, and deploy in one go
cp setup/simple-config.yaml my-config.yaml
# Edit my-config.yaml with your values
./setup/generate-config.sh my-config.yaml --auto-deploy
```

### **Environment-Specific Deployment**
```bash
# Development
cp setup/simple-config.yaml dev.yaml
./setup/generate-config.sh dev.yaml -o dev/

# Staging  
cp setup/webp-migrator-config.yaml staging.yaml
./setup/generate-config.sh staging.yaml -o staging/

# Production
cp setup/webp-migrator-config.yaml production.yaml
./setup/generate-config.sh production.yaml -o production/
```

## 📚 Related Documentation

- **[📖 Complete Configuration Guide](README-CONFIG-SYSTEM.md)** - All available options
- **[🎛️ Operations Index](OPERATIONS_INDEX.md)** - Quick task navigation
- **[🎯 Command Cheat Sheet](COMMAND_CHEAT_SHEET.md)** - All commands reference
- **[🚀 Quick Start Guide](QUICK_START.md)** - Setup walkthrough

---

**💡 Pro Tips:**
- Always use `"auto"` for passwords in config files - much more secure
- Test your configuration with `--validate-only` before deploying
- Keep different config files for development/staging/production
- Use non-privileged ports (>1024) to avoid permission issues
- Version control your config files but exclude generated passwords

**⚠️ Security Notes:**
- Never commit real passwords to version control
- Use `"auto"` password generation for production
- Change default usernames (don't use "admin", "wordpress")
- Review generated passwords before using in production
