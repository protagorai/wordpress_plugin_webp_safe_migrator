#!/usr/bin/env python3
"""
WebP Safe Migrator - Configuration Generator
Generates all configuration files from YAML config for autonomous setup
"""

import yaml
import os
import sys
import secrets
import string
import argparse
from pathlib import Path
from typing import Dict, Any, List
import hashlib
import base64

class ConfigGenerator:
    def __init__(self, config_file: str, output_dir: str = "."):
        """Initialize the configuration generator"""
        self.config_file = config_file
        self.output_dir = Path(output_dir)
        self.config = self._load_config()
        
    def _load_config(self) -> Dict[str, Any]:
        """Load configuration from YAML file"""
        try:
            with open(self.config_file, 'r') as f:
                return yaml.safe_load(f)
        except Exception as e:
            print(f"Error loading config file {self.config_file}: {e}")
            sys.exit(1)
    
    def _generate_random_password(self, length: int = 16) -> str:
        """Generate a secure random password"""
        alphabet = string.ascii_letters + string.digits + "!@#$%^&*"
        return ''.join(secrets.choice(alphabet) for _ in range(length))
    
    def _generate_wp_salt(self) -> str:
        """Generate WordPress salt/key"""
        alphabet = string.ascii_letters + string.digits + "!@#$%^&*()-_[]{}<>~`+=,.;:/?|"
        return ''.join(secrets.choice(alphabet) for _ in range(64))
    
    def _substitute_auto_values(self):
        """Substitute auto-generated values where needed"""
        # Auto-generate WordPress salts if empty
        security = self.config['wordpress']['security']
        salt_keys = ['auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key',
                    'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt']
        
        for key in salt_keys:
            if not security.get(key):
                security[key] = self._generate_wp_salt()
                
        # Auto-generate passwords if they contain 'auto' or are insecure defaults
        if 'auto' in self.config['database']['root_user']['password'].lower():
            self.config['database']['root_user']['password'] = self._generate_random_password()
            
        if 'auto' in self.config['database']['wordpress_user']['password'].lower():
            self.config['database']['wordpress_user']['password'] = self._generate_random_password()
            
        if 'auto' in self.config['wordpress']['admin_user']['password'].lower():
            self.config['wordpress']['admin_user']['password'] = self._generate_random_password()
    
    def generate_docker_compose(self) -> str:
        """Generate docker-compose.yml file"""
        db_config = self.config['database']
        wp_config = self.config['wordpress']
        infra_config = self.config['infrastructure']
        ssl_config = self.config['ssl']
        services_config = self.config['services']
        
        # Use alternative ports if specified
        ports = infra_config['ports']
        http_port = ports.get('alt_http', ports['http']) if ports.get('http') == 80 else ports['http']
        https_port = ports.get('alt_https', ports['https']) if ports.get('https') == 443 else ports['https']
        mysql_port = ports.get('alt_mysql', ports['mysql']) if ports.get('mysql') == 3306 else ports['mysql']
        
        compose_content = f"""version: '3.8'

services:
  wordpress:
    build:
      context: .
      dockerfile: Dockerfile
      args:
        WORDPRESS_VERSION: {wp_config['version']}
        PHP_VERSION: {self.config['php']['version']}
    container_name: webp-migrator-wordpress
    ports:
      - "{http_port}:80"
      - "{https_port}:443"
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_USER: {db_config['wordpress_user']['username']}
      WORDPRESS_DB_PASSWORD: {db_config['wordpress_user']['password']}
      WORDPRESS_DB_NAME: {db_config['name']}
      WORDPRESS_DEBUG: {1 if wp_config['config']['debug'] else 0}
      ENABLE_SSL: "{str(ssl_config['enabled']).lower()}"
      CUSTOM_DOMAIN: "{self.config['networking']['custom_domain']}"
      WORDPRESS_CONFIG_EXTRA: |
        define('WP_DEBUG_LOG', {str(wp_config['config']['debug_log']).lower()});
        define('WP_DEBUG_DISPLAY', {str(wp_config['config']['debug_display']).lower()});
        define('SCRIPT_DEBUG', {str(wp_config['config']['script_debug']).lower()});
        define('WP_MEMORY_LIMIT', '{wp_config['config']['wp_memory_limit']}');
        define('FS_METHOD', '{wp_config['config']['fs_method']}');
        define('FORCE_SSL_ADMIN', {str(wp_config['config']['force_ssl_admin']).lower()});
        
        /* WordPress Security Keys & Salts */
        define('AUTH_KEY',         '{wp_config['security']['auth_key']}');
        define('SECURE_AUTH_KEY',  '{wp_config['security']['secure_auth_key']}');
        define('LOGGED_IN_KEY',    '{wp_config['security']['logged_in_key']}');
        define('NONCE_KEY',        '{wp_config['security']['nonce_key']}');
        define('AUTH_SALT',        '{wp_config['security']['auth_salt']}');
        define('SECURE_AUTH_SALT', '{wp_config['security']['secure_auth_salt']}');
        define('LOGGED_IN_SALT',   '{wp_config['security']['logged_in_salt']}');
        define('NONCE_SALT',       '{wp_config['security']['nonce_salt']}');
        
        /* WebP Safe Migrator specific settings */
        define('WEBP_MIGRATOR_DEV_MODE', {str(self.config['plugins']['webp_migrator']['dev_mode']).lower()});
        define('WEBP_MIGRATOR_LOG_LEVEL', '{self.config['plugins']['webp_migrator']['log_level']}');
    volumes:
      - wordpress_data:/var/www/html
      - ../src:/var/www/html/wp-content/plugins/webp-safe-migrator
      - ./ssl-certs:/etc/ssl/certs/webp-migrator
      - ./logs:/var/log/webp-migrator
    depends_on:
      - db
    restart: unless-stopped
    networks:
      - {self.config['networking']['container_network']}

  db:
    image: mysql:{db_config['version']}
    container_name: webp-migrator-mysql
    environment:
      MYSQL_DATABASE: {db_config['name']}
      MYSQL_USER: {db_config['wordpress_user']['username']}
      MYSQL_PASSWORD: {db_config['wordpress_user']['password']}
      MYSQL_ROOT_PASSWORD: {db_config['root_user']['password']}
      MYSQL_INITDB_SKIP_TZINFO: 1
    volumes:
      - db_data:/var/lib/mysql
      - ./mysql-init:/docker-entrypoint-initdb.d
    ports:
      - "{mysql_port}:3306"
    command: >
      --default-authentication-plugin=mysql_native_password
      --character-set-server={db_config['charset']}
      --collation-server={db_config['collation']}
      --max_allowed_packet={db_config['performance']['max_allowed_packet']}
      --innodb_buffer_pool_size={db_config['performance']['innodb_buffer_pool_size']}
    restart: unless-stopped
    networks:
      - {self.config['networking']['container_network']}
"""

        # Add phpMyAdmin if enabled
        if services_config.get('phpmyadmin', {}).get('enabled', True):
            compose_content += f"""
  phpmyadmin:
    image: phpmyadmin:latest
    container_name: webp-migrator-phpmyadmin
    ports:
      - "{ports['phpmyadmin']}:80"
    environment:
      PMA_HOST: db
      PMA_USER: {db_config['root_user']['username']}
      PMA_PASSWORD: {db_config['root_user']['password']}
      PMA_ARBITRARY: 1
      UPLOAD_LIMIT: {services_config['phpmyadmin']['upload_limit']}
    depends_on:
      - db
    restart: unless-stopped
    networks:
      - {self.config['networking']['container_network']}
"""

        # Add Redis if enabled
        if services_config.get('redis', {}).get('enabled', False):
            compose_content += f"""
  redis:
    image: redis:{services_config['redis']['version']}
    container_name: webp-migrator-redis
    ports:
      - "{ports['redis']}:6379"
    command: redis-server --maxmemory {services_config['redis']['max_memory']} --maxmemory-policy allkeys-lru
    volumes:
      - redis_data:/data
    restart: unless-stopped
    networks:
      - {self.config['networking']['container_network']}
"""

        # Add volumes and networks
        compose_content += f"""
volumes:
  wordpress_data:
    driver: local
  db_data:
    driver: local
"""

        if services_config.get('redis', {}).get('enabled', False):
            compose_content += f"""  redis_data:
    driver: local
"""

        compose_content += f"""
networks:
  {self.config['networking']['container_network']}:
    driver: {self.config['networking']['network_driver']}
"""

        return compose_content
    
    def generate_env_file(self) -> str:
        """Generate .env file"""
        db_config = self.config['database']
        wp_config = self.config['wordpress']
        infra_config = self.config['infrastructure']
        ssl_config = self.config['ssl']
        php_config = self.config['php']
        
        env_content = f"""# WebP Safe Migrator - Generated Environment Configuration
# Generated automatically from webp-migrator-config.yaml

# Infrastructure Configuration
CONTAINER_ENGINE={infra_config['container_engine']}
INSTALL_PATH={infra_config['install_path']}

# WordPress Configuration
WORDPRESS_VERSION={wp_config['version']}
PHP_VERSION={php_config['version']}

# Domain Configuration
CUSTOM_DOMAIN={self.config['networking']['custom_domain']}

# SSL Configuration
ENABLE_SSL={str(ssl_config['enabled']).lower()}

# Database Configuration
MYSQL_DATABASE={db_config['name']}
MYSQL_USER={db_config['wordpress_user']['username']}
MYSQL_PASSWORD={db_config['wordpress_user']['password']}
MYSQL_ROOT_PASSWORD={db_config['root_user']['password']}

# Port Configuration
HTTP_PORT={infra_config['ports']['http']}
HTTPS_PORT={infra_config['ports']['https']}
MYSQL_PORT={infra_config['ports']['mysql']}
PHPMYADMIN_PORT={infra_config['ports']['phpmyadmin']}

# WordPress Debug Settings
WORDPRESS_DEBUG={1 if wp_config['config']['debug'] else 0}
WP_DEBUG_LOG={str(wp_config['config']['debug_log']).lower()}
WP_DEBUG_DISPLAY={str(wp_config['config']['debug_display']).lower()}
SCRIPT_DEBUG={str(wp_config['config']['script_debug']).lower()}

# Plugin Configuration
WEBP_MIGRATOR_DEV_MODE={str(self.config['plugins']['webp_migrator']['dev_mode']).lower()}
WEBP_MIGRATOR_LOG_LEVEL={self.config['plugins']['webp_migrator']['log_level']}

# Performance Settings
WP_MEMORY_LIMIT={wp_config['config']['wp_memory_limit']}
PHP_MEMORY_LIMIT={php_config['settings']['memory_limit']}
PHP_MAX_EXECUTION_TIME={php_config['settings']['max_execution_time']}
PHP_UPLOAD_MAX_FILESIZE={php_config['settings']['upload_max_filesize']}
PHP_POST_MAX_SIZE={php_config['settings']['post_max_size']}

# Web Server
WEB_SERVER={self.config['webserver']['type']}
"""
        return env_content
    
    def generate_mysql_init_sql(self) -> str:
        """Generate MySQL initialization SQL"""
        db_config = self.config['database']
        
        sql_content = f"""-- WebP Safe Migrator - Generated MySQL Initialization Script
-- Generated automatically from configuration

-- Ensure UTF8MB4 support for full Unicode compatibility
ALTER DATABASE {db_config['name']} CHARACTER SET {db_config['charset']} COLLATE {db_config['collation']};

-- Create additional test user
CREATE USER IF NOT EXISTS '{db_config['test_user']['username']}'@'%' IDENTIFIED BY '{db_config['test_user']['password']}';
GRANT ALL PRIVILEGES ON {db_config['name']}.* TO '{db_config['test_user']['username']}'@'%';

-- Optimize MySQL settings for development
SET GLOBAL max_allowed_packet = {db_config['performance']['max_allowed_packet']};
SET GLOBAL innodb_buffer_pool_size = {db_config['performance']['innodb_buffer_pool_size']};
SET GLOBAL max_connections = {db_config['performance']['max_connections']};

-- Set query cache if specified
SET GLOBAL query_cache_size = {db_config['performance']['query_cache_size']};

-- Flush privileges to ensure all changes take effect
FLUSH PRIVILEGES;

-- Log the initialization
SELECT 'WebP Safe Migrator database initialized successfully' as message;
"""
        return sql_content
    
    def generate_install_script(self) -> str:
        """Generate automated installation script"""
        wp_config = self.config['wordpress']
        automation_config = self.config['automation']
        
        script_content = f"""#!/bin/bash
# WebP Safe Migrator - Generated Automated Installation Script
# Generated automatically from configuration

set -e

# Configuration variables
SITE_TITLE="{wp_config['site']['title']}"
ADMIN_USER="{wp_config['admin_user']['username']}"
ADMIN_PASSWORD="{wp_config['admin_user']['password']}"
ADMIN_EMAIL="{wp_config['admin_user']['email']}"
SITE_URL="{wp_config['site']['url']}"
LANGUAGE="{wp_config['site']['language']}"

# Colors for output
RED='\\033[0;31m'
GREEN='\\033[0;32m'
YELLOW='\\033[1;33m'
CYAN='\\033[0;36m'
NC='\\033[0m' # No Color

log_info() {{
    echo -e "${{CYAN}}[INFO]${{NC}} $1"
}}

log_success() {{
    echo -e "${{GREEN}}[SUCCESS]${{NC}} $1"
}}

log_error() {{
    echo -e "${{RED}}[ERROR]${{NC}} $1"
}}

# Wait for database to be ready
wait_for_database() {{
    log_info "Waiting for database to be ready..."
    for i in {{1..{automation_config['max_wait_time']}}}; do
        if docker-compose exec -T db mysql -u {self.config['database']['wordpress_user']['username']} -p{self.config['database']['wordpress_user']['password']} -e "SELECT 1;" {self.config['database']['name']} >/dev/null 2>&1; then
            log_success "Database is ready"
            return 0
        fi
        if [[ $i -eq {automation_config['max_wait_time']} ]]; then
            log_error "Database not ready after {automation_config['max_wait_time']} seconds"
            return 1
        fi
        sleep 1
    done
}}

# Install WordPress core
install_wordpress() {{
    log_info "Installing WordPress core..."
    
    docker-compose exec -T wpcli wp core install \\
        --url="$SITE_URL" \\
        --title="$SITE_TITLE" \\
        --admin_user="$ADMIN_USER" \\
        --admin_password="$ADMIN_PASSWORD" \\
        --admin_email="$ADMIN_EMAIL" \\
        --locale="$LANGUAGE" \\
        --skip-email
        
    log_success "WordPress core installed"
}}

# Create additional users
create_additional_users() {{
"""

        # Add additional users
        for user in wp_config.get('additional_users', []):
            script_content += f"""    log_info "Creating user: {user['username']}"
    docker-compose exec -T wpcli wp user create \\
        {user['username']} {user['email']} \\
        --user_pass="{user['password']}" \\
        --role="{user['role']}" \\
        --first_name="{user.get('first_name', '')}" \\
        --last_name="{user.get('last_name', '')}"
"""

        script_content += f"""
}}

# Install and activate plugins
install_plugins() {{
"""

        # Add plugin installations
        for plugin in self.config['plugins']['install']:
            if plugin['source'] == 'local':
                script_content += f"""    log_info "Activating plugin: {plugin['slug']}"
    docker-compose exec -T wpcli wp plugin activate {plugin['slug']}
"""
            elif plugin['source'] == 'wordpress_org':
                activate_flag = '--activate' if plugin.get('activate', False) else ''
                script_content += f"""    log_info "Installing plugin from WordPress.org: {plugin['slug']}"
    docker-compose exec -T wpcli wp plugin install {plugin['slug']} {activate_flag}
"""
            elif plugin['source'] == 'zip_url':
                activate_flag = '--activate' if plugin.get('activate', False) else ''
                script_content += f"""    log_info "Installing plugin from URL: {plugin['slug']}"
    docker-compose exec -T wpcli wp plugin install {plugin['url']} {activate_flag}
"""

        script_content += f"""
}}

# Create sample content
create_sample_content() {{
"""

        # Add sample posts/pages
        for content in wp_config['content'].get('sample_posts', []):
            script_content += f"""    log_info "Creating {content['type']}: {content['title']}"
    docker-compose exec -T wpcli wp post create \\
        --post_type={content['type']} \\
        --post_title="{content['title']}" \\
        --post_content="{content['content']}" \\
        --post_status={content['status']}
"""

        script_content += f"""
}}

# Main installation function
main() {{
    log_info "Starting automated WordPress installation..."
    
    {"wait_for_database" if automation_config['wait_for_services'] else "# Skipping database wait"}
    {"install_wordpress" if automation_config['auto_install_wordpress'] else "# Skipping WordPress installation"}
    {"create_additional_users" if wp_config.get('additional_users') else "# No additional users to create"}
    {"install_plugins" if automation_config['auto_activate_plugins'] else "# Skipping plugin installation"}
    {"create_sample_content" if automation_config['auto_create_content'] else "# Skipping sample content creation"}
    
    # Post-installation actions
"""

        for action in automation_config.get('post_install', []):
            if action == 'flush_rewrite_rules':
                script_content += f"""    docker-compose exec -T wpcli wp rewrite flush
"""
            elif action == 'set_permissions':
                script_content += f"""    docker-compose exec wordpress chown -R www-data:www-data /var/www/html
"""

        script_content += f"""
    log_success "Installation completed successfully!"
    
    echo ""
    echo -e "${{CYAN}}🌐 WordPress Site: {wp_config['site']['url']}${{NC}}"
    echo -e "${{CYAN}}🔧 Admin Panel: {wp_config['site']['url']}/wp-admin${{NC}}"
    echo -e "${{YELLOW}}👤 Username: {wp_config['admin_user']['username']}${{NC}}"
    echo -e "${{YELLOW}}🔑 Password: {wp_config['admin_user']['password']}${{NC}}"
}}

# Run main function
main "$@"
"""
        
        return script_content
    
    def generate_all_configs(self):
        """Generate all configuration files"""
        print("🔧 WebP Safe Migrator Configuration Generator")
        print("=" * 50)
        
        # Substitute auto-generated values
        self._substitute_auto_values()
        
        # Create output directory
        self.output_dir.mkdir(exist_ok=True)
        
        # Generate all configuration files
        configs = {
            'docker-compose.yml': self.generate_docker_compose(),
            '.env': self.generate_env_file(),
            'mysql-init/01-webp-migrator-init.sql': self.generate_mysql_init_sql(),
            'install-automated.sh': self.generate_install_script()
        }
        
        for filename, content in configs.items():
            file_path = self.output_dir / filename
            file_path.parent.mkdir(parents=True, exist_ok=True)
            
            with open(file_path, 'w') as f:
                f.write(content)
            
            # Make shell scripts executable
            if filename.endswith('.sh'):
                os.chmod(file_path, 0o755)
                
            print(f"✅ Generated: {file_path}")
        
        print("\n🎉 Configuration generation complete!")
        print(f"\n📁 Files generated in: {self.output_dir}")
        print("\n🚀 To start your environment:")
        print("   docker-compose up -d")
        print("   ./install-automated.sh")

def main():
    parser = argparse.ArgumentParser(description='Generate WebP Migrator configuration files')
    parser.add_argument('config', help='Path to YAML configuration file')
    parser.add_argument('-o', '--output', default='.', help='Output directory (default: current directory)')
    parser.add_argument('-v', '--verbose', action='store_true', help='Verbose output')
    
    args = parser.parse_args()
    
    # Validate input file
    if not Path(args.config).exists():
        print(f"❌ Configuration file not found: {args.config}")
        sys.exit(1)
    
    # Generate configurations
    try:
        generator = ConfigGenerator(args.config, args.output)
        generator.generate_all_configs()
    except Exception as e:
        print(f"❌ Error generating configurations: {e}")
        if args.verbose:
            import traceback
            traceback.print_exc()
        sys.exit(1)

if __name__ == '__main__':
    main()
