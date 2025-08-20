#!/bin/bash
# WebP Safe Migrator Plugin Management Script - ENHANCED
# Handles complete plugin lifecycle with database operations, API support, and optional WP-CLI integration

set -e

# Default configuration
WORDPRESS_PATH="$HOME/webp-migrator-test/www"
SOURCE_PATH="./src"
BACKUP_PATH="./backups"
FORCE=false
USE_WPCLI=false
AUTO_ACTIVATE=true
WITH_DATABASE=true
SETUP_API=false

# Plugin configuration
PLUGIN_SLUG="webp-safe-migrator"
BACKUP_TIMESTAMP=$(date +"%Y%m%d-%H%M%S")

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
GRAY='\033[0;37m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${CYAN}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Show usage
show_usage() {
    echo "WebP Safe Migrator Plugin Manager (ENHANCED)"
    echo ""
    echo "Usage: $0 ACTION [OPTIONS]"
    echo ""
    echo "Actions:"
    echo "  install      Install plugin with optional auto-activation"
    echo "  update       Update plugin preserving configuration"
    echo "  uninstall    Complete removal with database cleanup"
    echo "  backup       Create backup (files + database)"
    echo "  restore      Restore from backup"
    echo "  activate     Activate plugin via WP-CLI"
    echo "  deactivate   Deactivate plugin via WP-CLI"
    echo "  status       Show comprehensive plugin status"
    echo "  cleanup      Database cleanup only"
    echo "  setup-db     Database setup only"
    echo ""
    echo "Options:"
    echo "  --wordpress-path PATH    WordPress installation path (default: ~/webp-migrator-test/www)"
    echo "  --source-path PATH       Plugin source path (default: ./src)"
    echo "  --backup-path PATH       Backup storage path (default: ./backups)"
    echo "  --force                  Skip confirmation prompts"
    echo "  --use-wpcli             Enable WP-CLI operations"
    echo "  --no-auto-activate      Disable auto-activation after install"
    echo "  --no-database           Disable database operations"
    echo "  --setup-api             Create API configuration"
    echo "  --help                  Show this help message"
    echo ""
    echo "Examples:"
    echo "  $0 install --use-wpcli --setup-api"
    echo "  $0 uninstall --force"
    echo "  $0 status"
}

# Parse command line arguments
parse_args() {
    if [[ $# -eq 0 ]]; then
        show_usage
        exit 1
    fi

    ACTION="$1"
    shift

    while [[ $# -gt 0 ]]; do
        case $1 in
            --wordpress-path)
                WORDPRESS_PATH="$2"
                shift 2
                ;;
            --source-path)
                SOURCE_PATH="$2"
                shift 2
                ;;
            --backup-path)
                BACKUP_PATH="$2"
                shift 2
                ;;
            --force)
                FORCE=true
                shift
                ;;
            --use-wpcli)
                USE_WPCLI=true
                shift
                ;;
            --no-auto-activate)
                AUTO_ACTIVATE=false
                shift
                ;;
            --no-database)
                WITH_DATABASE=false
                shift
                ;;
            --setup-api)
                SETUP_API=true
                shift
                ;;
            --help)
                show_usage
                exit 0
                ;;
            *)
                log_error "Unknown option: $1"
                show_usage
                exit 1
                ;;
        esac
    done

    # Validate action
    case $ACTION in
        install|update|uninstall|backup|restore|activate|deactivate|status|cleanup|setup-db)
            ;;
        *)
            log_error "Unknown action: $ACTION"
            show_usage
            exit 1
            ;;
    esac
}

# Set derived paths
set_paths() {
    PLUGIN_DIR="$WORDPRESS_PATH/wp-content/plugins/$PLUGIN_SLUG"
    WPCONFIG_PATH="$WORDPRESS_PATH/wp-config.php"
    WPCLI_PATH="$WORDPRESS_PATH/wp-cli.phar"
}

# Test WordPress installation
test_wordpress_installation() {
    if [[ ! -f "$WPCONFIG_PATH" ]]; then
        log_error "WordPress installation not found at $WORDPRESS_PATH"
        exit 1
    fi

    if [[ ! -d "$WORDPRESS_PATH/wp-content/plugins" ]]; then
        log_error "WordPress plugins directory not found"
        exit 1
    fi

    log_success "WordPress installation verified"
}

# Get WordPress database configuration
get_wordpress_config() {
    local config_file="$WPCONFIG_PATH"
    
    if [[ ! -f "$config_file" ]]; then
        log_error "WordPress configuration file not found"
        return 1
    fi

    DB_NAME=$(grep "define.*DB_NAME" "$config_file" | cut -d "'" -f 4)
    DB_USER=$(grep "define.*DB_USER" "$config_file" | cut -d "'" -f 4)
    DB_PASSWORD=$(grep "define.*DB_PASSWORD" "$config_file" | cut -d "'" -f 4)
    DB_HOST=$(grep "define.*DB_HOST" "$config_file" | cut -d "'" -f 4)

    if [[ -z "$DB_NAME" || -z "$DB_USER" || -z "$DB_HOST" ]]; then
        log_error "Could not parse WordPress database configuration"
        return 1
    fi

    return 0
}

# Test if WP-CLI is available
test_wpcli_available() {
    if [[ "$USE_WPCLI" == true ]] && [[ -f "$WPCLI_PATH" ]]; then
        return 0
    fi
    return 1
}

# Install WP-CLI
install_wpcli() {
    if [[ -f "$WPCLI_PATH" ]]; then
        log_success "WP-CLI already installed"
        return 0
    fi

    if [[ "$USE_WPCLI" != true ]]; then
        return 1
    fi

    log_info "Installing WP-CLI..."
    
    if ! command -v curl >/dev/null 2>&1; then
        log_error "curl is required to install WP-CLI"
        return 1
    fi

    curl -o "$WPCLI_PATH" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
    chmod +x "$WPCLI_PATH"

    # Create wp wrapper script
    cat > "$WORDPRESS_PATH/wp" << EOF
#!/bin/bash
cd "$WORDPRESS_PATH"
php "$WPCLI_PATH" "\$@"
EOF
    chmod +x "$WORDPRESS_PATH/wp"

    log_success "WP-CLI installed successfully"
    return 0
}

# Execute WP-CLI command
invoke_wpcli() {
    local command="$1"
    
    if ! test_wpcli_available; then
        if ! install_wpcli; then
            log_warning "WP-CLI not available, falling back to direct methods"
            return 1
        fi
    fi

    local original_dir=$(pwd)
    cd "$WORDPRESS_PATH"
    
    local result=0
    php "$WPCLI_PATH" $command || result=$?
    
    cd "$original_dir"
    return $result
}

# Execute database query
invoke_database_query() {
    local query="$1"
    local return_results="${2:-false}"
    
    if [[ "$WITH_DATABASE" != true ]]; then
        log_warning "Database operations disabled"
        return 1
    fi

    # Try WP-CLI first if available
    if test_wpcli_available; then
        local format_arg=""
        if [[ "$return_results" == true ]]; then
            format_arg="--format=json"
        fi
        
        if invoke_wpcli "db query \"$query\" $format_arg"; then
            return 0
        fi
    fi

    # Fallback to direct MySQL connection
    if ! get_wordpress_config; then
        return 1
    fi

    local mysql_args="-h$DB_HOST -u$DB_USER"
    if [[ -n "$DB_PASSWORD" ]]; then
        mysql_args="$mysql_args -p$DB_PASSWORD"
    fi
    mysql_args="$mysql_args $DB_NAME"

    if [[ "$return_results" == true ]]; then
        mysql_args="$mysql_args --batch --skip-column-names"
    fi

    if command -v mysql >/dev/null 2>&1; then
        mysql $mysql_args -e "$query"
        return $?
    else
        log_warning "MySQL client not found. Install WP-CLI or ensure MySQL client is available"
        return 1
    fi
}

# Setup plugin database
setup_plugin_database() {
    log_info "Setting up plugin database..."

    # Verify WordPress tables exist
    local tables=("wp_options" "wp_postmeta" "wp_posts")
    local all_tables_exist=true

    for table in "${tables[@]}"; do
        local query="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$table'"
        if ! invoke_database_query "$query" true >/dev/null 2>&1; then
            all_tables_exist=false
            break
        fi
    done

    if [[ "$all_tables_exist" != true ]]; then
        log_error "WordPress database tables not found. Please ensure WordPress is properly installed"
        return 1
    fi

    log_success "WordPress database tables verified"

    # Initialize plugin options with defaults if they don't exist
    local option_name="webp_safe_migrator_settings"
    local check_query="SELECT COUNT(*) FROM wp_options WHERE option_name = '$option_name'"
    
    local exists=$(invoke_database_query "$check_query" true 2>/dev/null | head -1)
    if [[ "$exists" == "0" ]] || [[ -z "$exists" ]]; then
        local default_value='{"quality":59,"batch_size":10,"validation":1,"skip_folders":"","skip_mimes":""}'
        local insert_query="INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('$option_name', '$default_value', 'yes')"
        
        if invoke_database_query "$insert_query"; then
            log_success "Initialized option: $option_name"
        fi
    fi

    return 0
}

# Cleanup plugin database
cleanup_plugin_database() {
    log_info "Cleaning up plugin database entries..."

    local queries=(
        "DELETE FROM wp_options WHERE option_name LIKE 'webp_%'"
        "DELETE FROM wp_postmeta WHERE meta_key LIKE '_webp_%'"
        "DELETE FROM wp_options WHERE option_name = 'webp_migrator_queue'"
        "DELETE FROM wp_options WHERE option_name = 'webp_migrator_progress'"
    )

    local cleaned=0
    for query in "${queries[@]}"; do
        if invoke_database_query "$query"; then
            ((cleaned++))
        fi
    done

    # Clear scheduled hooks if WP-CLI is available
    if test_wpcli_available; then
        invoke_wpcli "cron event delete webp_migrator_process_queue" >/dev/null 2>&1 || true
    fi

    if [[ $cleaned -gt 0 ]]; then
        log_success "Database cleanup completed ($cleaned operations)"
        return 0
    else
        log_warning "Database cleanup may not have completed successfully"
        return 1
    fi
}

# Get plugin status
get_plugin_status() {
    local status_installed=false
    local status_active=false
    local status_version="Unknown"
    local status_database_clean=true
    local status_wpcli_available=false
    local status_database_connected=false

    # Check if plugin is installed
    if [[ -d "$PLUGIN_DIR" ]]; then
        status_installed=true

        # Get version from plugin file
        local main_file=$(find "$PLUGIN_DIR" -name "*webp*migrator*.php" | head -1)
        if [[ -n "$main_file" ]]; then
            status_version=$(grep "Version:" "$main_file" | head -1 | sed 's/.*Version:[[:space:]]*//' | sed 's/[[:space:]]*$//')
        fi
    fi

    # Check WP-CLI availability
    if test_wpcli_available; then
        status_wpcli_available=true

        # Check if plugin is active
        if invoke_wpcli "plugin is-active $PLUGIN_SLUG" >/dev/null 2>&1; then
            status_active=true
        fi
    fi

    # Check database status
    if [[ "$WITH_DATABASE" == true ]]; then
        local options_query="SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'webp_%'"
        local postmeta_query="SELECT COUNT(*) FROM wp_postmeta WHERE meta_key LIKE '_webp_%'"

        local options_count=$(invoke_database_query "$options_query" true 2>/dev/null | head -1)
        local postmeta_count=$(invoke_database_query "$postmeta_query" true 2>/dev/null | head -1)

        if [[ -n "$options_count" ]]; then
            status_database_connected=true
            if [[ "$options_count" == "0" ]] && [[ "$postmeta_count" == "0" ]]; then
                status_database_clean=true
            else
                status_database_clean=false
            fi
        fi
    fi

    # Export status variables for use by other functions
    STATUS_INSTALLED=$status_installed
    STATUS_ACTIVE=$status_active
    STATUS_VERSION="$status_version"
    STATUS_DATABASE_CLEAN=$status_database_clean
    STATUS_WPCLI_AVAILABLE=$status_wpcli_available
    STATUS_DATABASE_CONNECTED=$status_database_connected
}

# Setup plugin API
setup_plugin_api() {
    if [[ "$SETUP_API" != true ]]; then
        return 0
    fi

    log_info "Setting up plugin API endpoints..."

    local api_config='<?php
/**
 * WebP Safe Migrator API Configuration
 * Auto-generated by plugin manager
 */

// REST API endpoint registration
add_action('\''rest_api_init'\'', function() {
    register_rest_route('\''webp-migrator/v1'\'', '\''/status'\'', array(
        '\''methods'\'' => '\''GET'\'',
        '\''callback'\'' => '\''webp_migrator_api_status'\'',
        '\''permission_callback'\'' => function() {
            return current_user_can('\''manage_options'\'');
        }
    ));
    
    register_rest_route('\''webp-migrator/v1'\'', '\''/process'\'', array(
        '\''methods'\'' => '\''POST'\'',
        '\''callback'\'' => '\''webp_migrator_api_process'\'',
        '\''permission_callback'\'' => function() {
            return current_user_can('\''manage_options'\'');
        }
    ));
});

function webp_migrator_api_status() {
    if (class_exists('\''WebP_Safe_Migrator'\'')) {
        $plugin = WebP_Safe_Migrator::instance();
        return array(
            '\''status'\'' => '\''active'\'',
            '\''version'\'' => '\''0.2.0'\'',
            '\''queue_status'\'' => '\''ready'\''
        );
    }
    return new WP_Error('\''plugin_not_active'\'', '\''Plugin not active'\'', array('\''status'\'' => 500));
}

function webp_migrator_api_process() {
    // API processing logic would go here
    return array('\''message'\'' => '\''Processing started'\'');
}'

    echo "$api_config" > "$PLUGIN_DIR/api-config.php"
    log_success "API configuration created at: $PLUGIN_DIR/api-config.php"
}

# Create plugin backup
backup_plugin() {
    local backup_name="${1:-$BACKUP_TIMESTAMP}"
    
    if [[ ! -d "$PLUGIN_DIR" ]]; then
        log_warning "No existing plugin to backup"
        return 1
    fi

    local backup_full_path="$BACKUP_PATH/$PLUGIN_SLUG-$backup_name"
    mkdir -p "$backup_full_path"

    log_info "Creating plugin file backup..."
    cp -r "$PLUGIN_DIR/"* "$backup_full_path/"

    # Backup database settings if enabled
    if [[ "$WITH_DATABASE" == true ]]; then
        log_info "Creating database backup..."

        local db_backup_file="$backup_full_path/database-backup.json"
        
        # Create JSON structure
        echo "{" > "$db_backup_file"
        echo "  \"timestamp\": \"$(date '+%Y-%m-%d %H:%M:%S')\"," >> "$db_backup_file"
        echo "  \"options\": [" >> "$db_backup_file"

        # Backup plugin options
        local options_query="SELECT option_name, option_value FROM wp_options WHERE option_name LIKE 'webp_%'"
        if invoke_database_query "$options_query" true > /tmp/options_backup.txt 2>/dev/null; then
            local first=true
            while IFS=$'\t' read -r name value; do
                if [[ "$first" == true ]]; then
                    first=false
                else
                    echo "," >> "$db_backup_file"
                fi
                echo "    {\"name\": \"$name\", \"value\": \"$value\"}" >> "$db_backup_file"
            done < /tmp/options_backup.txt
            rm -f /tmp/options_backup.txt
        fi

        echo "  ]," >> "$db_backup_file"
        echo "  \"postmeta\": [" >> "$db_backup_file"

        # Backup plugin postmeta
        local postmeta_query="SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE meta_key LIKE '_webp_%'"
        if invoke_database_query "$postmeta_query" true > /tmp/postmeta_backup.txt 2>/dev/null; then
            local first=true
            while IFS=$'\t' read -r post_id key value; do
                if [[ "$first" == true ]]; then
                    first=false
                else
                    echo "," >> "$db_backup_file"
                fi
                echo "    {\"post_id\": \"$post_id\", \"meta_key\": \"$key\", \"meta_value\": \"$value\"}" >> "$db_backup_file"
            done < /tmp/postmeta_backup.txt
            rm -f /tmp/postmeta_backup.txt
        fi

        echo "  ]" >> "$db_backup_file"
        echo "}" >> "$db_backup_file"

        log_success "Database backup included"
    fi

    log_success "Plugin backed up to: $backup_full_path"
    echo "$backup_full_path"
}

# Install plugin
install_plugin() {
    log_info "Installing WebP Safe Migrator plugin..."

    # Verify source files exist
    if [[ ! -d "$SOURCE_PATH" ]]; then
        log_error "Source directory not found: $SOURCE_PATH"
        exit 1
    fi

    local main_file=$(find "$SOURCE_PATH" -name "*webp*migrator*.php" | head -1)
    if [[ -z "$main_file" ]]; then
        log_error "Main plugin file not found in source directory"
        exit 1
    fi

    # Create plugin directory
    if [[ -d "$PLUGIN_DIR" ]]; then
        if [[ "$FORCE" != true ]]; then
            echo -n "Plugin directory exists. Overwrite? (y/N): "
            read -r response
            if [[ "$response" != "y" ]] && [[ "$response" != "Y" ]]; then
                log_warning "Installation cancelled"
                return 0
            fi
        fi

        # Backup existing installation
        backup_plugin >/dev/null
        rm -rf "$PLUGIN_DIR"
    fi

    mkdir -p "$PLUGIN_DIR"

    # Copy plugin files
    log_info "Copying plugin files..."
    cp -r "$SOURCE_PATH/"* "$PLUGIN_DIR/"

    # Create or update uninstall.php for proper cleanup
    cat > "$PLUGIN_DIR/uninstall.php" << 'EOF'
<?php
/**
 * WebP Safe Migrator Uninstall Script
 * Cleans up all plugin data when uninstalled via WordPress admin
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin options
delete_option('webp_safe_migrator_settings');
delete_option('webp_migrator_queue');
delete_option('webp_migrator_progress');

// Remove all plugin postmeta
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_webp_%'");

// Remove backup directories
$upload_dir = wp_get_upload_dir();
$backup_dir = trailingslashit($upload_dir['basedir']) . 'webp-migrator-backup';
if (is_dir($backup_dir)) {
    function webp_migrator_rrmdir($dir) {
        if (!is_dir($dir)) return false;
        $files = scandir($dir);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $f;
            if (is_dir($path)) webp_migrator_rrmdir($path); else @unlink($path);
        }
        return @rmdir($dir);
    }
    webp_migrator_rrmdir($backup_dir);
}

// Clear scheduled hooks
wp_clear_scheduled_hook('webp_migrator_process_queue');
EOF

    # Setup database if enabled
    if [[ "$WITH_DATABASE" == true ]]; then
        setup_plugin_database >/dev/null
    fi

    # Setup API if requested
    if [[ "$SETUP_API" == true ]]; then
        setup_plugin_api
    fi

    log_success "Plugin installed successfully!"
    log_info "Location: $PLUGIN_DIR"

    # Auto-activate plugin if requested and WP-CLI is available
    if [[ "$AUTO_ACTIVATE" == true ]] && ([[ "$USE_WPCLI" == true ]] || test_wpcli_available); then
        log_info "Attempting to activate plugin..."

        if ! test_wpcli_available; then
            install_wpcli >/dev/null
        fi

        if test_wpcli_available; then
            if invoke_wpcli "plugin activate $PLUGIN_SLUG" >/dev/null 2>&1; then
                log_success "Plugin activated successfully!"
            else
                log_warning "Plugin activation failed. You can activate manually in WordPress admin"
            fi
        fi
    fi

    # Check if WordPress is accessible
    if curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080" | grep -q "200\|30[0-9]"; then
        echo ""
        log_info "WordPress is running. Access:"
        echo "1. Admin Panel: http://localhost:8080/wp-admin/"
        echo "2. Plugin Settings: Media → WebP Migrator"
        if [[ "$SETUP_API" == true ]]; then
            echo "3. API Endpoint: http://localhost:8080/wp-json/webp-migrator/v1/status"
        fi
    fi
}

# Update plugin
update_plugin() {
    log_info "Updating WebP Safe Migrator plugin..."

    if [[ ! -d "$PLUGIN_DIR" ]]; then
        log_warning "Plugin not currently installed. Use 'install' action instead"
        return 0
    fi

    # Always backup before updating
    local backup_path=$(backup_plugin "pre-update-$BACKUP_TIMESTAMP")

    # Remove old files but keep configuration
    local config_files=("wp-config.php" "settings.json" "*.log")
    local temp_config_path="/tmp/webp-migrator-config-$BACKUP_TIMESTAMP"
    mkdir -p "$temp_config_path"

    for pattern in "${config_files[@]}"; do
        find "$PLUGIN_DIR" -name "$pattern" -exec cp {} "$temp_config_path/" \; 2>/dev/null || true
    done

    # Install new version
    rm -rf "$PLUGIN_DIR"
    mkdir -p "$PLUGIN_DIR"
    cp -r "$SOURCE_PATH/"* "$PLUGIN_DIR/"

    # Restore configuration files
    cp "$temp_config_path/"* "$PLUGIN_DIR/" 2>/dev/null || true

    # Cleanup temp directory
    rm -rf "$temp_config_path"

    log_success "Plugin updated successfully!"
    log_info "Backup created at: $backup_path"
}

# Uninstall plugin
uninstall_plugin() {
    log_info "Uninstalling WebP Safe Migrator plugin..."

    if [[ ! -d "$PLUGIN_DIR" ]]; then
        log_warning "Plugin is not installed"
        return 0
    fi

    if [[ "$FORCE" != true ]]; then
        echo -n "Are you sure you want to uninstall the plugin? This will remove all files and database data. (y/N): "
        read -r response
        if [[ "$response" != "y" ]] && [[ "$response" != "Y" ]]; then
            log_warning "Uninstallation cancelled"
            return 0
        fi

        echo -n "Create backup before uninstalling? (Y/n): "
        read -r backup_response
        if [[ "$backup_response" != "n" ]] && [[ "$backup_response" != "N" ]]; then
            backup_plugin "pre-uninstall-$BACKUP_TIMESTAMP" >/dev/null
        fi
    fi

    # Deactivate plugin first if WP-CLI is available
    if test_wpcli_available || [[ "$USE_WPCLI" == true ]]; then
        log_info "Deactivating plugin..."

        if ! test_wpcli_available; then
            install_wpcli >/dev/null
        fi

        if test_wpcli_available; then
            if invoke_wpcli "plugin deactivate $PLUGIN_SLUG" >/dev/null 2>&1; then
                log_success "Plugin deactivated"
            fi
        fi
    fi

    # Clean up database if enabled
    if [[ "$WITH_DATABASE" == true ]]; then
        cleanup_plugin_database >/dev/null

        # Remove backup directories
        log_info "Removing backup directories..."
        if get_wordpress_config; then
            # Get upload directory from WordPress
            local upload_query="SELECT option_value FROM wp_options WHERE option_name = 'upload_path'"
            local upload_path=$(invoke_database_query "$upload_query" true 2>/dev/null | head -1)

            if [[ -z "$upload_path" ]]; then
                upload_path="$WORDPRESS_PATH/wp-content/uploads"
            fi

            local backup_dir="$upload_path/webp-migrator-backup"
            if [[ -d "$backup_dir" ]]; then
                rm -rf "$backup_dir"
                log_success "Backup directories removed"
            fi
        fi
    fi

    # Remove plugin directory
    rm -rf "$PLUGIN_DIR"

    log_success "Plugin uninstalled successfully!"

    if [[ "$WITH_DATABASE" == true ]]; then
        log_success "All plugin data has been removed from the database"
    else
        echo ""
        log_warning "Database operations were disabled"
        log_info "To manually remove plugin data, run:"
        echo "  $0 cleanup --with-database"
    fi
}

# Restore plugin from backup
restore_plugin() {
    log_info "Restoring WebP Safe Migrator plugin from backup..."

    if [[ ! -d "$BACKUP_PATH" ]]; then
        log_error "Backup directory not found: $BACKUP_PATH"
        exit 1
    fi

    # List available backups
    local backups=($(find "$BACKUP_PATH" -type d -name "$PLUGIN_SLUG-*" | sort -r))

    if [[ ${#backups[@]} -eq 0 ]]; then
        log_warning "No backups found"
        return 0
    fi

    echo "Available backups:"
    for i in "${!backups[@]}"; do
        local backup_name=$(basename "${backups[$i]}")
        echo "  $((i+1)). $backup_name"
    done

    echo -n "Select backup to restore (1-${#backups[@]}): "
    read -r selection

    if ! [[ "$selection" =~ ^[0-9]+$ ]] || [[ $selection -lt 1 ]] || [[ $selection -gt ${#backups[@]} ]]; then
        log_error "Invalid selection"
        return 1
    fi

    local selected_backup="${backups[$((selection-1))]}"
    local backup_name=$(basename "$selected_backup")

    # Backup current installation if it exists
    if [[ -d "$PLUGIN_DIR" ]]; then
        backup_plugin "pre-restore-$BACKUP_TIMESTAMP" >/dev/null
        rm -rf "$PLUGIN_DIR"
    fi

    # Restore from backup
    mkdir -p "$PLUGIN_DIR"
    cp -r "$selected_backup/"* "$PLUGIN_DIR/"

    log_success "Plugin restored from backup: $backup_name"
}

# Show plugin information
show_plugin_info() {
    echo ""
    echo -e "${CYAN}=== Plugin Information ===${NC}"

    get_plugin_status

    if [[ "$STATUS_INSTALLED" == true ]]; then
        echo -e "Status: ${GREEN}Installed${NC}"
    else
        echo -e "Status: ${RED}Not Installed${NC}"
    fi

    if [[ "$STATUS_INSTALLED" == true ]]; then
        echo "Location: $PLUGIN_DIR"
        echo "Version: $STATUS_VERSION"
        if [[ "$STATUS_ACTIVE" == true ]]; then
            echo -e "Active: ${GREEN}Yes${NC}"
        else
            echo -e "Active: ${YELLOW}Unknown${NC}"
        fi

        # Show directory size
        local size=$(du -sh "$PLUGIN_DIR" 2>/dev/null | cut -f1)
        echo "Size: $size"

        # Count files
        local file_count=$(find "$PLUGIN_DIR" -type f | wc -l)
        echo "Files: $file_count"
    fi

    # Database status
    if [[ "$WITH_DATABASE" == true ]]; then
        echo ""
        echo -e "${CYAN}Database Status:${NC}"
        if [[ "$STATUS_DATABASE_CONNECTED" == true ]]; then
            echo -e "Connected: ${GREEN}Yes${NC}"
        else
            echo -e "Connected: ${RED}No${NC}"
        fi
        if [[ "$STATUS_DATABASE_CLEAN" == true ]]; then
            echo -e "Clean: ${GREEN}Yes${NC}"
        else
            echo -e "Clean: ${YELLOW}No${NC}"
        fi
    fi

    # WP-CLI status
    echo ""
    if [[ "$STATUS_WPCLI_AVAILABLE" == true ]]; then
        echo -e "WP-CLI Available: ${GREEN}Yes${NC}"
    else
        echo -e "WP-CLI Available: ${YELLOW}No${NC}"
    fi

    # Show available backups
    if [[ -d "$BACKUP_PATH" ]]; then
        local backups=($(find "$BACKUP_PATH" -type d -name "$PLUGIN_SLUG-*" | sort -r))
        if [[ ${#backups[@]} -gt 0 ]]; then
            echo ""
            echo -e "${CYAN}Available Backups: ${#backups[@]}${NC}"
            for i in "${!backups[@]}"; do
                if [[ $i -lt 5 ]]; then
                    local backup_name=$(basename "${backups[$i]}")
                    echo -e "  ${GRAY}$backup_name${NC}"
                fi
            done
            if [[ ${#backups[@]} -gt 5 ]]; then
                echo -e "  ${GRAY}... and $((${#backups[@]} - 5)) more${NC}"
            fi
        fi
    fi
}

# Main execution
main() {
    echo -e "${GREEN}=== WebP Safe Migrator Plugin Manager (ENHANCED) ===${NC}"
    echo "Action: $ACTION"
    echo "WordPress Path: $WORDPRESS_PATH"
    echo "Plugin Directory: $PLUGIN_DIR"
    echo "Use WP-CLI: $USE_WPCLI"
    echo "Database Operations: $WITH_DATABASE"
    echo ""

    test_wordpress_installation

    case $ACTION in
        install)
            install_plugin
            ;;
        update)
            update_plugin
            ;;
        uninstall)
            uninstall_plugin
            ;;
        backup)
            local backup_path=$(backup_plugin)
            if [[ -n "$backup_path" ]]; then
                log_success "Backup created successfully at: $backup_path"
            fi
            ;;
        restore)
            restore_plugin
            ;;
        activate)
            if test_wpcli_available || [[ "$USE_WPCLI" == true ]]; then
                if ! test_wpcli_available; then
                    install_wpcli >/dev/null
                fi

                if test_wpcli_available; then
                    if invoke_wpcli "plugin activate $PLUGIN_SLUG"; then
                        log_success "Plugin activated successfully!"
                    else
                        log_error "Plugin activation failed"
                    fi
                fi
            else
                log_warning "Activation requires WP-CLI. Use --use-wpcli option or activate manually in WordPress admin"
            fi
            ;;
        deactivate)
            if test_wpcli_available || [[ "$USE_WPCLI" == true ]]; then
                if ! test_wpcli_available; then
                    install_wpcli >/dev/null
                fi

                if test_wpcli_available; then
                    if invoke_wpcli "plugin deactivate $PLUGIN_SLUG"; then
                        log_success "Plugin deactivated successfully!"
                    else
                        log_error "Plugin deactivation failed"
                    fi
                fi
            else
                log_warning "Deactivation requires WP-CLI. Use --use-wpcli option or deactivate manually in WordPress admin"
            fi
            ;;
        status)
            show_plugin_info
            return 0
            ;;
        cleanup)
            if [[ "$WITH_DATABASE" == true ]]; then
                if cleanup_plugin_database; then
                    log_success "Database cleanup completed successfully!"
                else
                    log_warning "Database cleanup may have failed. Check the output above"
                fi
            else
                log_warning "Database operations are disabled. Use --with-database option"
            fi
            ;;
        setup-db)
            if [[ "$WITH_DATABASE" == true ]]; then
                if setup_plugin_database; then
                    log_success "Database setup completed successfully!"
                else
                    log_error "Database setup failed. Check the output above"
                fi
            else
                log_warning "Database operations are disabled. Use --with-database option"
            fi
            ;;
    esac

    if [[ "$ACTION" != "status" ]]; then
        show_plugin_info
    fi
}

# Show usage examples for status action
show_usage_examples() {
    if [[ "$ACTION" == "status" ]]; then
        echo ""
        echo -e "${CYAN}=== Usage Examples ===${NC}"
        echo -e "${NC}Install with auto-activation:${NC}"
        echo -e "  ${GRAY}$0 install --use-wpcli${NC}"
        echo ""
        echo -e "${NC}Install with database setup and API:${NC}"
        echo -e "  ${GRAY}$0 install --setup-api${NC}"
        echo ""
        echo -e "${NC}Complete uninstall with database cleanup:${NC}"
        echo -e "  ${GRAY}$0 uninstall --use-wpcli${NC}"
        echo ""
        echo -e "${NC}Database operations only:${NC}"
        echo -e "  ${GRAY}$0 setup-db${NC}"
        echo -e "  ${GRAY}$0 cleanup${NC}"
    fi
}

# Parse arguments and set paths
parse_args "$@"
set_paths

# Execute main function
main

# Show usage examples if status
show_usage_examples

echo ""
log_success "Operation completed successfully!"
