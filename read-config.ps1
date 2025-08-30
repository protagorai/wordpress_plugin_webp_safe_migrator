# WebP Migrator Configuration Reader
# This PowerShell script reads the YAML configuration file and sets environment variables

param(
    [string]$ConfigFile = "webp-migrator.config.yaml"
)

# Function to parse simple YAML (basic key-value pairs)
function Parse-SimpleYaml {
    param([string]$YamlContent)
    
    $config = @{}
    $lines = $YamlContent -split "`n"
    $currentSection = ""
    
    foreach ($line in $lines) {
        $line = $line.Trim()
        
        # Skip comments and empty lines
        if ($line -match "^#" -or $line -eq "") {
            continue
        }
        
        # Handle sections (indented keys)
        if ($line -match "^(\w+):$") {
            $currentSection = $matches[1]
            $config[$currentSection] = @{}
            continue
        }
        
        # Handle subsections (double indented)
        if ($line -match "^  (\w+):$") {
            $subSection = $matches[1]
            if (-not $config.ContainsKey($currentSection)) {
                $config[$currentSection] = @{}
            }
            $config[$currentSection][$subSection] = @{}
            continue
        }
        
        # Handle key-value pairs in main section
        if ($line -match "^(\w+):\s*[""']?([^""'#]*)[""']?" -and $currentSection -eq "") {
            $config[$matches[1]] = $matches[2].Trim()
            continue
        }
        
        # Handle key-value pairs in sections
        if ($line -match "^  (\w+):\s*[""']?([^""'#]*)[""']?") {
            $key = $matches[1]
            $value = $matches[2].Trim()
            if ($config.ContainsKey($currentSection)) {
                $config[$currentSection][$key] = $value
            }
            continue
        }
        
        # Handle key-value pairs in subsections
        if ($line -match "^    (\w+):\s*[""']?([^""'#]*)[""']?") {
            $key = $matches[1]
            $value = $matches[2].Trim()
            # Find the current subsection
            foreach ($section in $config.Keys) {
                if ($config[$section] -is [hashtable]) {
                    foreach ($subsection in $config[$section].Keys) {
                        if ($config[$section][$subsection] -is [hashtable]) {
                            $config[$section][$subsection][$key] = $value
                            break
                        }
                    }
                }
            }
            continue
        }
    }
    
    return $config
}

# Check if config file exists
if (-not (Test-Path $ConfigFile)) {
    Write-Host "WARNING: Config file '$ConfigFile' not found. Using default values." -ForegroundColor Yellow
    
    # Set default environment variables
    $env:WP_ADMIN_USER = "admin"
    $env:WP_ADMIN_PASS = "admin123"
    $env:WP_ADMIN_EMAIL = "admin@webp-test.local"
    $env:DB_WP_USER = "wordpress"
    $env:DB_WP_PASS = "wordpress123"
    $env:DB_ROOT_PASS = "root123"
    $env:DB_NAME = "wordpress_webp_test"
    $env:WP_PORT = "8080"
    $env:DB_PORT = "3307"
    $env:PMA_PORT = "8081"
    $env:WP_SITE_TITLE = "WebP Migrator Test Site"
    $env:WP_SITE_URL = "http://localhost:8080"
    
    Write-Host "Using default credentials - WordPress admin: admin / admin123" -ForegroundColor Green
    return
}

try {
    # Read and parse the YAML config file
    $yamlContent = Get-Content -Path $ConfigFile -Raw -Encoding UTF8
    $config = Parse-SimpleYaml $yamlContent
    
    # Extract WordPress admin credentials
    if ($config.ContainsKey("wordpress_admin")) {
        $env:WP_ADMIN_USER = if ($config["wordpress_admin"]["username"]) { $config["wordpress_admin"]["username"] } else { "admin" }
        $env:WP_ADMIN_PASS = if ($config["wordpress_admin"]["password"]) { $config["wordpress_admin"]["password"] } else { "admin123" }
        $env:WP_ADMIN_EMAIL = if ($config["wordpress_admin"]["email"]) { $config["wordpress_admin"]["email"] } else { "admin@webp-test.local" }
        $env:WP_ADMIN_FIRST = if ($config["wordpress_admin"]["first_name"]) { $config["wordpress_admin"]["first_name"] } else { "WebP" }
        $env:WP_ADMIN_LAST = if ($config["wordpress_admin"]["last_name"]) { $config["wordpress_admin"]["last_name"] } else { "Admin" }
    } else {
        $env:WP_ADMIN_USER = "admin"
        $env:WP_ADMIN_PASS = "admin123"
        $env:WP_ADMIN_EMAIL = "admin@webp-test.local"
        $env:WP_ADMIN_FIRST = "WebP"
        $env:WP_ADMIN_LAST = "Admin"
    }
    
    # Extract database credentials
    if ($config.ContainsKey("database")) {
        $env:DB_WP_USER = if ($config["database"]["wp_user"]["username"]) { $config["database"]["wp_user"]["username"] } else { "wordpress" }
        $env:DB_WP_PASS = if ($config["database"]["wp_user"]["password"]) { $config["database"]["wp_user"]["password"] } else { "wordpress123" }
        $env:DB_ROOT_PASS = if ($config["database"]["root_user"]["password"]) { $config["database"]["root_user"]["password"] } else { "root123" }
        $env:DB_NAME = if ($config["database"]["database_name"]) { $config["database"]["database_name"] } else { "wordpress_webp_test" }
    } else {
        $env:DB_WP_USER = "wordpress"
        $env:DB_WP_PASS = "wordpress123"
        $env:DB_ROOT_PASS = "root123"
        $env:DB_NAME = "wordpress_webp_test"
    }
    
    # Extract port configuration
    if ($config.ContainsKey("infrastructure") -and $config["infrastructure"].ContainsKey("ports")) {
        $env:WP_PORT = if ($config["infrastructure"]["ports"]["wordpress"]) { $config["infrastructure"]["ports"]["wordpress"] } else { "8080" }
        $env:DB_PORT = if ($config["infrastructure"]["ports"]["mysql"]) { $config["infrastructure"]["ports"]["mysql"] } else { "3307" }
        $env:PMA_PORT = if ($config["infrastructure"]["ports"]["phpmyadmin"]) { $config["infrastructure"]["ports"]["phpmyadmin"] } else { "8081" }
    } else {
        $env:WP_PORT = "8080"
        $env:DB_PORT = "3307"
        $env:PMA_PORT = "8081"
    }
    
    # Extract WordPress site settings
    if ($config.ContainsKey("wordpress_site")) {
        $env:WP_SITE_TITLE = if ($config["wordpress_site"]["title"]) { $config["wordpress_site"]["title"] } else { "WebP Migrator Test Site" }
        $env:WP_SITE_URL = if ($config["wordpress_site"]["url"]) { $config["wordpress_site"]["url"] } else { "http://localhost:$($env:WP_PORT)" }
    } else {
        $env:WP_SITE_TITLE = "WebP Migrator Test Site"
        $env:WP_SITE_URL = "http://localhost:$($env:WP_PORT)"
    }
    
    # Output configuration summary
    Write-Host "Configuration loaded from: $ConfigFile" -ForegroundColor Green
    Write-Host "WordPress Admin: $($env:WP_ADMIN_USER) / $($env:WP_ADMIN_PASS)" -ForegroundColor Cyan
    Write-Host "WordPress URL: $($env:WP_SITE_URL)" -ForegroundColor Cyan
    Write-Host "Database: $($env:DB_NAME) (WordPress user: $($env:DB_WP_USER))" -ForegroundColor Cyan
    Write-Host "Ports - WP:$($env:WP_PORT), MySQL:$($env:DB_PORT), phpMyAdmin:$($env:PMA_PORT)" -ForegroundColor Cyan
    
} catch {
    Write-Host "ERROR: Failed to parse config file '$ConfigFile': $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "Using default values instead." -ForegroundColor Yellow
    
    # Fallback to defaults
    $env:WP_ADMIN_USER = "admin"
    $env:WP_ADMIN_PASS = "admin123"
    $env:WP_ADMIN_EMAIL = "admin@webp-test.local"
    $env:DB_WP_USER = "wordpress"
    $env:DB_WP_PASS = "wordpress123"
    $env:DB_ROOT_PASS = "root123"
    $env:DB_NAME = "wordpress_webp_test"
    $env:WP_PORT = "8080"
    $env:DB_PORT = "3307"
    $env:PMA_PORT = "8081"
    $env:WP_SITE_TITLE = "WebP Migrator Test Site"
    $env:WP_SITE_URL = "http://localhost:8080"
}
