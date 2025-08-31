#!/bin/bash
# Multi-Plugin Manager for WordPress Development Environment
# Enhanced plugin management with support for multiple plugins, deployment profiles, and configuration management

set -e

# =============================================================================
# SCRIPT PARAMETERS AND DEFAULTS
# =============================================================================

ACTION=""
PLUGIN=""
PROFILE="development"
WORDPRESS_PATH="${WORDPRESS_PATH:-/var/www/html}"
SOURCE_PATH="${SOURCE_PATH:-./src}"
BACKUP_PATH="${BACKUP_PATH:-./backups}"
CONFIG_PATH="${CONFIG_PATH:-./bin/config}"
FORCE=false
USE_WPCLI=false
AUTO_ACTIVATE=true
WITH_DATABASE=true
DRY_RUN=false
VERBOSE=false

BACKUP_TIMESTAMP=$(date +"%Y%m%d-%H%M%S")
WPCLI_PATH="$WORDPRESS_PATH/wp-cli.phar"
WP_CONFIG_PATH="$WORDPRESS_PATH/wp-config.php"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
GRAY='\033[0;37m'
NC='\033[0m' # No Color

# =============================================================================
# UTILITY FUNCTIONS
# =============================================================================

log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
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

log_verbose() {
    if [[ "$VERBOSE" == true ]]; then
        echo -e "${GRAY}[VERBOSE]${NC} $1"
    fi
}

show_help() {
    cat << EOF
Multi-Plugin Manager for WordPress Development Environment

USAGE:
    $0 ACTION [OPTIONS]

ACTIONS:
    install-all         Install all plugins for specified profile
    install            Install specific plugin
    deploy-profile     Deploy plugins for specified profile (alias for install-all)
    update             Update specific plugin
    uninstall          Remove specific plugin
    activate           Activate specific plugin
    deactivate         Deactivate specific plugin
    status             Show status of all plugins
    list               List all available plugins
    cleanup            Clean up backups and temporary files

OPTIONS:
    --plugin SLUG      Specific plugin slug (for single plugin operations)
    --profile NAME     Deployment profile (development, production, testing, custom)
    --wordpress-path   WordPress installation path (default: /var/www/html)
    --source-path      Source plugins directory (default: ./src)
    --backup-path      Backup directory (default: ./backups)
    --config-path      Configuration directory (default: ./bin/config)
    --force            Force operation without confirmation
    --use-wpcli        Use WP-CLI for operations
    --no-activate      Don't auto-activate plugins
    --dry-run          Show what would be done without executing
    --verbose          Show verbose output
    --help             Show this help message

EXAMPLES:
    $0 install-all --profile development
    $0 install --plugin okvir-image-safe-migrator --use-wpcli
    $0 status --verbose
    $0 list
    $0 deploy-profile --profile production --force

EOF
}

# =============================================================================
# ARGUMENT PARSING
# =============================================================================

parse_arguments() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            install-all|install|deploy-profile|update|uninstall|activate|deactivate|status|list|cleanup)
                ACTION="$1"
                ;;
            --plugin)
                PLUGIN="$2"
                shift
                ;;
            --profile)
                PROFILE="$2"
                shift
                ;;
            --wordpress-path)
                WORDPRESS_PATH="$2"
                shift
                ;;
            --source-path)
                SOURCE_PATH="$2"
                shift
                ;;
            --backup-path)
                BACKUP_PATH="$2"
                shift
                ;;
            --config-path)
                CONFIG_PATH="$2"
                shift
                ;;
            --force)
                FORCE=true
                ;;
            --use-wpcli)
                USE_WPCLI=true
                ;;
            --no-activate)
                AUTO_ACTIVATE=false
                ;;
            --dry-run)
                DRY_RUN=true
                ;;
            --verbose)
                VERBOSE=true
                ;;
            --help)
                show_help
                exit 0
                ;;
            *)
                log_error "Unknown option: $1"
                show_help
                exit 1
                ;;
        esac
        shift
    done

    if [[ -z "$ACTION" ]]; then
        log_error "Action is required"
        show_help
        exit 1
    fi
}

# =============================================================================
# CONFIGURATION LOADING
# =============================================================================

load_plugin_configuration() {
    local plugins_config_file="$CONFIG_PATH/plugins.yaml"
    local main_config_file="$CONFIG_PATH/webp-migrator.config.yaml"

    if [[ ! -f "$plugins_config_file" ]]; then
        log_error "Plugin configuration file not found: $plugins_config_file"
        exit 1
    fi

    log_info "Loading plugin configuration from $plugins_config_file..."

    # Create temporary files for parsed configuration
    AVAILABLE_PLUGINS_FILE=$(mktemp)
    DEPLOYMENT_CONFIG_FILE=$(mktemp)

    # Basic YAML parsing (simplified implementation)
    parse_plugins_yaml "$plugins_config_file"

    if [[ -f "$main_config_file" ]]; then
        parse_main_config "$main_config_file"
    fi

    local plugin_count=$(wc -l < "$AVAILABLE_PLUGINS_FILE" 2>/dev/null || echo "0")
    log_verbose "Loaded configuration for $plugin_count available plugins"
}

parse_plugins_yaml() {
    local file_path="$1"
    local current_section=""
    local in_available=false
    local in_deployment=false
    local current_plugin=""
    local current_profile=""

    # Clear temp files
    > "$AVAILABLE_PLUGINS_FILE"
    > "$DEPLOYMENT_CONFIG_FILE"

    while IFS= read -r line; do
        line=$(echo "$line" | sed 's/^[[:space:]]*//' | sed 's/[[:space:]]*$//')

        # Skip comments and empty lines
        [[ "$line" =~ ^#.*$ ]] && continue
        [[ -z "$line" ]] && continue

        # Handle main sections
        if [[ "$line" =~ ^([a-z_]+):$ ]]; then
            current_section="${BASH_REMATCH[1]}"
            in_available=false
            in_deployment=false
            continue
        fi

        # Handle plugins section
        if [[ "$current_section" == "plugins" && "$line" =~ ^available:$ ]]; then
            in_available=true
            continue
        fi

        # Handle plugin entries
        if [[ "$in_available" == true && "$line" =~ ^-[[:space:]]+slug:[[:space:]]*["\']?([^"\']*)["\']? ]]; then
            current_plugin="${BASH_REMATCH[1]}"
            echo "PLUGIN_START:$current_plugin" >> "$AVAILABLE_PLUGINS_FILE"
            continue
        fi

        # Handle plugin properties
        if [[ -n "$current_plugin" && "$line" =~ ^([a-z_]+):[[:space:]]*["\']?([^"\']*)["\']? ]]; then
            local key="${BASH_REMATCH[1]}"
            local value="${BASH_REMATCH[2]}"
            echo "PLUGIN_PROP:$current_plugin:$key:$value" >> "$AVAILABLE_PLUGINS_FILE"
            continue
        fi

        # Handle deployment section
        if [[ "$current_section" == "deployment" && "$line" =~ ^([a-z_]+):$ ]]; then
            current_profile="${BASH_REMATCH[1]}"
            in_deployment=true
            echo "PROFILE_START:$current_profile" >> "$DEPLOYMENT_CONFIG_FILE"
            continue
        fi

        # Handle deployment plugins
        if [[ "$in_deployment" == true && "$line" =~ ^-[[:space:]]+slug:[[:space:]]*["\']?([^"\']*)["\']? ]]; then
            local plugin_slug="${BASH_REMATCH[1]}"
            echo "PROFILE_PLUGIN:$current_profile:$plugin_slug" >> "$DEPLOYMENT_CONFIG_FILE"
            continue
        fi

        # Handle plugin activation in deployment
        if [[ "$in_deployment" == true && "$line" =~ ^activate:[[:space:]]*([a-z]+) ]]; then
            local activate_value="${BASH_REMATCH[1]}"
            echo "PROFILE_ACTIVATE:$current_profile:$activate_value" >> "$DEPLOYMENT_CONFIG_FILE"
            continue
        fi

    done < "$file_path"
}

parse_main_config() {
    local file_path="$1"
    # Simple parsing for main config - in production would use proper YAML parser
    log_verbose "Parsing main configuration from $file_path"
}

# =============================================================================
# WORDPRESS VALIDATION
# =============================================================================

test_wordpress_installation() {
    if [[ ! -f "$WP_CONFIG_PATH" ]]; then
        log_error "WordPress installation not found at $WORDPRESS_PATH"
        exit 1
    fi

    if [[ ! -d "$WORDPRESS_PATH/wp-content/plugins" ]]; then
        log_error "WordPress plugins directory not found"
        exit 1
    fi

    log_success "WordPress installation verified at $WORDPRESS_PATH"
}

install_wpcli() {
    if [[ -f "$WPCLI_PATH" ]]; then
        return 0
    fi

    log_info "Installing WP-CLI..."
    if curl -L -o "$WPCLI_PATH" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar 2>/dev/null; then
        chmod +x "$WPCLI_PATH"
        log_success "WP-CLI installed successfully"
        return 0
    else
        log_warning "Failed to install WP-CLI"
        return 1
    fi
}

test_wpcli_available() {
    [[ -f "$WPCLI_PATH" && -f "$WP_CONFIG_PATH" ]]
}

invoke_wpcli() {
    local command="$1"
    
    if ! test_wpcli_available; then
        log_error "WP-CLI not available"
        return 1
    fi

    php "$WPCLI_PATH" $command --path="$WORDPRESS_PATH" 2>/dev/null
}

# =============================================================================
# PLUGIN DISCOVERY AND MANAGEMENT
# =============================================================================

get_available_plugins() {
    local plugins=()
    local current_plugin=""
    local plugin_data=""

    while IFS= read -r line; do
        if [[ "$line" =~ ^PLUGIN_START:(.+)$ ]]; then
            # Save previous plugin if exists
            if [[ -n "$current_plugin" && -n "$plugin_data" ]]; then
                plugins+=("$current_plugin|$plugin_data")
            fi
            
            current_plugin="${BASH_REMATCH[1]}"
            plugin_data=""
        elif [[ "$line" =~ ^PLUGIN_PROP:([^:]+):([^:]+):(.+)$ ]]; then
            local plugin="${BASH_REMATCH[1]}"
            local key="${BASH_REMATCH[2]}"
            local value="${BASH_REMATCH[3]}"
            
            if [[ "$plugin" == "$current_plugin" ]]; then
                plugin_data="${plugin_data}${key}=${value};"
            fi
        fi
    done < "$AVAILABLE_PLUGINS_FILE"

    # Save last plugin
    if [[ -n "$current_plugin" && -n "$plugin_data" ]]; then
        plugins+=("$current_plugin|$plugin_data")
    fi

    printf '%s\n' "${plugins[@]}"
}

get_plugins_for_deployment() {
    local profile_name="$1"
    local deployment_plugins=()
    local available_plugins=($(get_available_plugins))

    # Get plugins from deployment profile
    local profile_plugins=()
    while IFS= read -r line; do
        if [[ "$line" =~ ^PROFILE_PLUGIN:$profile_name:(.+)$ ]]; then
            profile_plugins+=("${BASH_REMATCH[1]}")
        fi
    done < "$DEPLOYMENT_CONFIG_FILE"

    # Match profile plugins with available plugins
    for profile_plugin in "${profile_plugins[@]}"; do
        for available_plugin in "${available_plugins[@]}"; do
            local slug="${available_plugin%%|*}"
            if [[ "$slug" == "$profile_plugin" ]]; then
                deployment_plugins+=("$available_plugin")
                break
            fi
        done
    done

    printf '%s\n' "${deployment_plugins[@]}"
}

# =============================================================================
# PLUGIN OPERATIONS
# =============================================================================

install_plugin() {
    local plugin_info="$1"
    local activate_plugin="$2"
    
    local slug="${plugin_info%%|*}"
    local data="${plugin_info##*|}"
    
    # Parse plugin data
    local name=""
    local path=""
    local main_file=""
    
    while IFS=';' read -ra PROPS; do
        for prop in "${PROPS[@]}"; do
            if [[ "$prop" =~ ^name=(.+)$ ]]; then
                name="${BASH_REMATCH[1]}"
            elif [[ "$prop" =~ ^path=(.+)$ ]]; then
                path="${BASH_REMATCH[1]}"
            elif [[ "$prop" =~ ^main_file=(.+)$ ]]; then
                main_file="${BASH_REMATCH[1]}"
            fi
        done
    done <<< "$data"

    local plugin_dir="$WORDPRESS_PATH/wp-content/plugins/$slug"
    local source_path="$SOURCE_PATH/$path"

    log_info "Installing plugin: $name ($slug)"

    if [[ "$DRY_RUN" == true ]]; then
        log_warning "[DRY RUN] Would install plugin to: $plugin_dir"
        return 0
    fi

    if [[ ! -d "$source_path" ]]; then
        log_error "Plugin source not found: $source_path"
        return 1
    fi

    # Backup existing plugin if it exists
    if [[ -d "$plugin_dir" ]]; then
        local backup_dir="$BACKUP_PATH/$slug-$BACKUP_TIMESTAMP"
        log_warning "Backing up existing plugin to: $backup_dir"
        mkdir -p "$BACKUP_PATH"
        cp -r "$plugin_dir" "$backup_dir"
    fi

    # Remove existing plugin directory
    if [[ -d "$plugin_dir" ]]; then
        rm -rf "$plugin_dir"
    fi

    # Copy plugin files
    log_info "Copying plugin files from: $source_path"
    cp -r "$source_path" "$plugin_dir"

    # Activate plugin if requested
    if [[ "$activate_plugin" == true && "$AUTO_ACTIVATE" == true ]]; then
        activate_plugin "$slug"
    fi

    log_success "Plugin '$slug' installed successfully"
    return 0
}

activate_plugin() {
    local plugin_slug="$1"

    if [[ "$DRY_RUN" == true ]]; then
        log_warning "[DRY RUN] Would activate plugin: $plugin_slug"
        return 0
    fi

    if [[ "$USE_WPCLI" == true && $(test_wpcli_available) ]]; then
        log_info "Activating plugin '$plugin_slug' via WP-CLI..."
        
        if invoke_wpcli "plugin activate $plugin_slug"; then
            log_success "Plugin '$plugin_slug' activated successfully"
            return 0
        else
            log_warning "Failed to activate plugin '$plugin_slug'"
            return 1
        fi
    else
        log_warning "Plugin activation requires WP-CLI. Plugin '$plugin_slug' installed but not activated."
        return 1
    fi
}

deactivate_plugin() {
    local plugin_slug="$1"

    if [[ "$DRY_RUN" == true ]]; then
        log_warning "[DRY RUN] Would deactivate plugin: $plugin_slug"
        return 0
    fi

    if [[ "$USE_WPCLI" == true && $(test_wpcli_available) ]]; then
        log_info "Deactivating plugin '$plugin_slug' via WP-CLI..."
        
        if invoke_wpcli "plugin deactivate $plugin_slug"; then
            log_success "Plugin '$plugin_slug' deactivated successfully"
            return 0
        else
            log_warning "Failed to deactivate plugin '$plugin_slug'"
            return 1
        fi
    else
        log_warning "Plugin deactivation requires WP-CLI."
        return 1
    fi
}

# =============================================================================
# MAIN ACTIONS
# =============================================================================

install_all_plugins() {
    local profile_name="$1"
    
    log_info "Installing plugins for profile: $profile_name"
    
    local plugins=($(get_plugins_for_deployment "$profile_name"))
    if [[ ${#plugins[@]} -eq 0 ]]; then
        log_warning "No plugins found for deployment profile: $profile_name"
        return 1
    fi

    log_info "Found ${#plugins[@]} plugins to deploy:"
    for plugin in "${plugins[@]}"; do
        local slug="${plugin%%|*}"
        local data="${plugin##*|}"
        local name=""
        
        # Extract name from plugin data
        if [[ "$data" =~ name=([^;]+) ]]; then
            name="${BASH_REMATCH[1]}"
        fi
        
        log_info "  - $name ($slug)"
    done

    if [[ "$DRY_RUN" == false && "$FORCE" == false ]]; then
        echo -n "Continue with installation? (y/N): "
        read -r confirm
        if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
            log_warning "Installation cancelled by user"
            return 0
        fi
    fi

    local success_count=0
    local error_count=0

    # Execute pre-deployment hooks
    execute_deployment_hooks "pre_deployment"

    for plugin in "${plugins[@]}"; do
        if install_plugin "$plugin" true; then
            ((success_count++))
        else
            ((error_count++))
        fi
    done

    # Execute post-deployment hooks
    execute_deployment_hooks "post_deployment"

    # Fix permissions once for all plugins
    fix_uploads_ownership

    # Execute post-activation hooks
    execute_deployment_hooks "post_activation"

    log_success "Installation completed: $success_count successful, $error_count errors"
}

execute_deployment_hooks() {
    local phase="$1"

    if [[ "$DRY_RUN" == true ]]; then
        log_warning "[DRY RUN] Would execute $phase hooks"
        return
    fi

    log_info "Executing $phase hooks..."
    
    # Implementation would read hooks from configuration and execute them
    case "$phase" in
        "pre_deployment")
            if test_wpcli_available; then
                invoke_wpcli "cache flush" || true
            fi
            ;;
        "post_deployment")
            if test_wpcli_available; then
                invoke_wpcli "rewrite flush" || true
            fi
            ;;
        "post_activation")
            if test_wpcli_available; then
                invoke_wpcli "cron event run --due-now" || true
            fi
            ;;
    esac
}

fix_uploads_ownership() {
    if [[ "$DRY_RUN" == true ]]; then
        log_warning "[DRY RUN] Would fix uploads directory ownership"
        return
    fi

    log_info "Fixing uploads directory ownership..."

    local uploads_dir="$WORDPRESS_PATH/wp-content/uploads"
    
    # Ensure uploads directory exists
    mkdir -p "$uploads_dir"
    
    # Fix ownership and permissions (adjust as needed for your environment)
    if command -v chown >/dev/null 2>&1; then
        chown -R www-data:www-data "$uploads_dir" 2>/dev/null || true
    fi
    
    chmod -R 755 "$uploads_dir" 2>/dev/null || true
}

show_plugin_status() {
    echo
    log_info "Plugin Status Report"
    echo "==================="

    local available_plugins=($(get_available_plugins))
    local deployment_plugins=($(get_plugins_for_deployment "$PROFILE"))

    echo
    log_info "Available Plugins: ${#available_plugins[@]}"
    for plugin in "${available_plugins[@]}"; do
        local slug="${plugin%%|*}"
        local data="${plugin##*|}"
        local name=""
        local path=""
        
        # Extract data
        if [[ "$data" =~ name=([^;]+) ]]; then
            name="${BASH_REMATCH[1]}"
        fi
        if [[ "$data" =~ path=([^;]+) ]]; then
            path="${BASH_REMATCH[1]}"
        fi
        
        local source_path="$SOURCE_PATH/$path"
        local status="Available"
        local color="$GREEN"
        
        if [[ ! -d "$source_path" ]]; then
            status="Missing"
            color="$RED"
        fi
        
        echo -e "  ${color}$name ($slug): $status${NC}"
    done

    echo
    log_info "Deployment Profile '$PROFILE': ${#deployment_plugins[@]} plugins"
    for plugin in "${deployment_plugins[@]}"; do
        local slug="${plugin%%|*}"
        local data="${plugin##*|}"
        local name=""
        
        if [[ "$data" =~ name=([^;]+) ]]; then
            name="${BASH_REMATCH[1]}"
        fi
        
        echo -e "  ${GRAY}$name ($slug): Auto-deploy${NC}"
    done

    # Check installed plugins via WP-CLI if available
    if [[ "$USE_WPCLI" == true && $(test_wpcli_available) ]]; then
        echo
        log_info "Installed WordPress Plugins:"
        invoke_wpcli "plugin list --format=table" || log_warning "Failed to get plugin list via WP-CLI"
    fi
}

show_available_plugins() {
    local available_plugins=($(get_available_plugins))

    echo
    log_info "Available Plugins for Development"
    echo "================================="

    for plugin in "${available_plugins[@]}"; do
        local slug="${plugin%%|*}"
        local data="${plugin##*|}"
        
        echo
        echo -e "${CYAN}$slug${NC}"
        
        # Parse and display all plugin data
        while IFS=';' read -ra PROPS; do
            for prop in "${PROPS[@]}"; do
                if [[ "$prop" =~ ^([^=]+)=(.+)$ ]]; then
                    local key="${BASH_REMATCH[1]}"
                    local value="${BASH_REMATCH[2]}"
                    echo "  $key: $value"
                fi
            done
        done <<< "$data"
        
        # Check if plugin exists
        local path=""
        if [[ "$data" =~ path=([^;]+) ]]; then
            path="${BASH_REMATCH[1]}"
        fi
        
        local source_path="$SOURCE_PATH/$path"
        local status="Available"
        if [[ ! -d "$source_path" ]]; then
            status="Missing"
        fi
        echo "  Status: $status"
    done
}

cleanup_temp_files() {
    [[ -f "$AVAILABLE_PLUGINS_FILE" ]] && rm -f "$AVAILABLE_PLUGINS_FILE"
    [[ -f "$DEPLOYMENT_CONFIG_FILE" ]] && rm -f "$DEPLOYMENT_CONFIG_FILE"
}

# =============================================================================
# MAIN EXECUTION
# =============================================================================

main() {
    # Parse command line arguments
    parse_arguments "$@"

    # Show header
    echo
    log_info "Multi-Plugin Manager for WordPress Development"
    log_info "Action: $ACTION"
    log_info "Profile: $PROFILE"
    log_info "WordPress Path: $WORDPRESS_PATH"
    if [[ -n "$PLUGIN" ]]; then
        log_info "Target Plugin: $PLUGIN"
    fi
    if [[ "$DRY_RUN" == true ]]; then
        log_warning "DRY RUN MODE - No changes will be made"
    fi
    echo

    # Set up cleanup trap
    trap cleanup_temp_files EXIT

    # Load configuration
    load_plugin_configuration

    # Validate WordPress installation
    test_wordpress_installation

    # Install WP-CLI if requested
    if [[ "$USE_WPCLI" == true ]] && ! test_wpcli_available; then
        install_wpcli
    fi

    # Execute requested action
    case "$ACTION" in
        "install-all"|"deploy-profile")
            install_all_plugins "$PROFILE"
            ;;
        "install")
            if [[ -z "$PLUGIN" ]]; then
                log_error "Plugin parameter is required for install action"
                exit 1
            fi
            log_error "Single plugin install not yet implemented"
            exit 1
            ;;
        "status")
            show_plugin_status
            ;;
        "list")
            show_available_plugins
            ;;
        "cleanup")
            log_warning "Cleanup functionality not yet implemented"
            ;;
        *)
            log_warning "Action '$ACTION' not yet implemented for multi-plugin manager"
            log_info "Use the legacy plugin-manager.sh for single plugin operations"
            ;;
    esac

    echo
    log_success "Multi-plugin operation completed successfully!"
}

# Execute main function with all arguments
main "$@"
