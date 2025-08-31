#!/bin/bash
# Configuration-Driven Plugin Deployment to Container (Linux/macOS)
# Reads plugins.yaml and deploys/activates plugins according to configuration

set -e

# Default parameters
CONTAINER_NAME=""
PROFILE="development"
DRY_RUN=false

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
GRAY='\033[0;37m'
NC='\033[0m'

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --container)
            CONTAINER_NAME="$2"
            shift 2
            ;;
        --profile)
            PROFILE="$2"
            shift 2
            ;;
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        *)
            CONTAINER_NAME="$1"
            shift
            ;;
    esac
done

if [[ -z "$CONTAINER_NAME" ]]; then
    echo -e "${RED}Error: Container name is required${NC}"
    echo "Usage: $0 CONTAINER_NAME [--profile PROFILE] [--dry-run]"
    exit 1
fi

echo -e "${GREEN}=== Configuration-Driven Plugin Deployment ===${NC}"
echo -e "${YELLOW}Container: $CONTAINER_NAME${NC}"
echo -e "${YELLOW}Profile: $PROFILE${NC}"
echo -e "${YELLOW}Dry Run: $DRY_RUN${NC}"

read_plugin_configuration() {
    local profile_name="$1"
    local config_file="bin/config/plugins.yaml"
    
    if [[ ! -f "$config_file" ]]; then
        echo -e "${YELLOW}Warning: Configuration file not found: $config_file${NC}"
        return 1
    fi
    
    echo -e "${CYAN}Reading plugin configuration for profile: $profile_name${NC}"
    
    # Simple parsing of YAML for deployment configuration
    local in_deployment=false
    local in_profile=false
    local plugins=()
    
    while IFS= read -r line; do
        line=$(echo "$line" | sed 's/^[[:space:]]*//' | sed 's/[[:space:]]*$//')
        
        # Skip comments and empty lines
        [[ "$line" =~ ^#.*$ ]] && continue
        [[ -z "$line" ]] && continue
        
        # Find deployment section
        if [[ "$line" =~ ^deployment:$ ]]; then
            in_deployment=true
            continue
        fi
        
        # Find our profile
        if [[ "$in_deployment" == true && "$line" =~ ^${profile_name}:$ ]]; then
            in_profile=true
            echo -e "${GREEN}  Found configuration for profile: $profile_name${NC}"
            continue
        fi
        
        # Stop parsing this profile if we hit another profile
        if [[ "$in_profile" == true && "$line" =~ ^[a-z]+:$ ]]; then
            in_profile=false
        fi
        
        # Parse plugin entries
        if [[ "$in_profile" == true && "$line" =~ ^-[[:space:]]+slug:[[:space:]]*[\"\']*([^\"\']*)[\"\']*$ ]]; then
            current_plugin="${BASH_REMATCH[1]}"
            plugins+=("$current_plugin:true")  # Default to activate
            continue
        fi
        
        # Parse activate setting
        if [[ "$in_profile" == true && "$line" =~ ^activate:[[:space:]]*([a-z]+)$ ]]; then
            if [[ ${#plugins[@]} -gt 0 ]]; then
                # Update last plugin's activation setting
                local last_plugin="${plugins[-1]}"
                local plugin_slug="${last_plugin%:*}"
                plugins[-1]="$plugin_slug:${BASH_REMATCH[1]}"
            fi
        fi
        
    done < "$config_file"
    
    echo -e "${GREEN}Configuration loaded: ${#plugins[@]} plugins for $profile_name${NC}"
    
    # Output plugins to stdout for parsing
    printf '%s\n' "${plugins[@]}"
}

get_available_plugins() {
    local plugins=()
    
    if [[ -d "src" ]]; then
        for plugin_dir in src/*/; do
            if [[ -d "$plugin_dir" ]]; then
                local plugin_name=$(basename "$plugin_dir")
                local php_files=($(ls "$plugin_dir"*.php 2>/dev/null || true))
                
                if [[ ${#php_files[@]} -gt 0 ]]; then
                    # Check for WordPress plugin header
                    local main_file="${php_files[0]}"
                    if grep -q "Plugin Name:" "$main_file" 2>/dev/null; then
                        local plugin_display_name=$(grep "Plugin Name:" "$main_file" | sed 's/.*Plugin Name:[[:space:]]*//' | sed 's/[[:space:]]*$//')
                        plugins+=("$plugin_name|$plugin_display_name|$plugin_dir")
                    fi
                fi
            fi
        done
    fi
    
    printf '%s\n' "${plugins[@]}"
}

deploy_plugins_to_container() {
    local container="$1"
    local profile="$2"
    
    # Check container exists and is running
    if ! podman inspect "$container" >/dev/null 2>&1; then
        echo -e "${RED}Error: Container '$container' not found${NC}"
        return 1
    fi
    
    # Get available plugins
    local available_plugins=($(get_available_plugins))
    echo -e "${CYAN}Found ${#available_plugins[@]} available plugins in src/${NC}"
    
    # Get deployment configuration
    local deployment_config=($(read_plugin_configuration "$profile"))
    
    if [[ ${#deployment_config[@]} -eq 0 ]]; then
        echo -e "${YELLOW}No deployment configuration found for profile '$profile'${NC}"
        echo -e "${YELLOW}Deploying all available plugins with default settings...${NC}"
        
        # Deploy all plugins, activate primary only
        for plugin_info in "${available_plugins[@]}"; do
            local plugin_slug="${plugin_info%%|*}"
            deployment_config+=("$plugin_slug:$([ "$plugin_slug" == "okvir-image-safe-migrator" ] && echo "true" || echo "false")")
        done
    fi
    
    echo -e "${CYAN}"
    echo "Deployment Plan:"
    echo "  Profile: $profile"
    echo "  Container: $container"
    echo "  Plugins to deploy: ${#deployment_config[@]}"
    echo -e "${NC}"
    
    for config_entry in "${deployment_config[@]}"; do
        local plugin_slug="${config_entry%:*}"
        local should_activate="${config_entry#*:}"
        local action="Deploy Only"
        [[ "$should_activate" == "true" ]] && action="Deploy + Activate"
        
        # Find plugin info
        for plugin_info in "${available_plugins[@]}"; do
            if [[ "$plugin_info" =~ ^$plugin_slug\| ]]; then
                local plugin_display_name=$(echo "$plugin_info" | cut -d'|' -f2)
                echo -e "${GRAY}    - $plugin_display_name ($plugin_slug): $action${NC}"
                break
            fi
        done
    done
    
    if [[ "$DRY_RUN" == true ]]; then
        echo -e "${YELLOW}"
        echo "[DRY RUN] Deployment plan ready - no actual changes made"
        echo -e "${NC}"
        return 0
    fi
    
    echo -e "${GREEN}"
    echo "Executing deployment..."
    echo -e "${NC}"
    
    local deployed_count=0
    local activated_count=0
    local error_count=0
    
    for config_entry in "${deployment_config[@]}"; do
        local plugin_slug="${config_entry%:*}"
        local should_activate="${config_entry#*:}"
        
        # Find plugin source path
        local plugin_source=""
        local plugin_name=""
        for plugin_info in "${available_plugins[@]}"; do
            if [[ "$plugin_info" =~ ^$plugin_slug\| ]]; then
                plugin_name=$(echo "$plugin_info" | cut -d'|' -f2)
                plugin_source=$(echo "$plugin_info" | cut -d'|' -f3)
                break
            fi
        done
        
        if [[ -z "$plugin_source" ]]; then
            echo -e "${RED}  ✗ Plugin '$plugin_slug' not found in src/${NC}"
            ((error_count++))
            continue
        fi
        
        echo -e "${CYAN}"
        echo "Deploying: $plugin_name"
        echo -e "${NC}"
        
        local target_path="/var/www/html/wp-content/plugins/$plugin_slug"
        
        # Remove existing plugin in container
        podman exec "$container" rm -rf "$target_path" 2>/dev/null || true
        
        # Copy plugin to container
        echo -e "${GRAY}  * Copying plugin files...${NC}"
        if podman cp "$plugin_source" "${container}:$target_path"; then
            echo -e "${GREEN}  ✓ Plugin deployed successfully${NC}"
            ((deployed_count++))
            
            # Fix permissions
            podman exec "$container" chown -R www-data:www-data "$target_path" 2>/dev/null || true
            
            # Activate if configured
            if [[ "$should_activate" == "true" ]]; then
                echo -e "${GRAY}  * Activating plugin...${NC}"
                if podman exec "$container" wp plugin activate "$plugin_slug" --allow-root 2>/dev/null; then
                    echo -e "${GREEN}  ✓ Plugin activated successfully${NC}"
                    ((activated_count++))
                else
                    echo -e "${YELLOW}  ! Plugin activation failed${NC}"
                fi
            else
                echo -e "${GRAY}  ○ Plugin deployed but not activated (per configuration)${NC}"
            fi
        else
            echo -e "${RED}  ✗ Plugin deployment failed${NC}"
            ((error_count++))
        fi
    done
    
    echo -e "${GREEN}"
    echo "=================================================="
    echo "Plugin Deployment Summary:"
    echo "  Deployed: $deployed_count plugins"
    echo "  Activated: $activated_count plugins"
    echo "  Errors: $error_count"
    echo "=================================================="
    echo -e "${NC}"
    
    return $error_count
}

# Main execution
deploy_plugins_to_container "$CONTAINER_NAME" "$PROFILE"
