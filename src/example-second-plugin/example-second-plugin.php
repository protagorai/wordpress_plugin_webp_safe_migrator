<?php
/**
 * Plugin Name: Example Second Plugin
 * Description: A demonstration plugin to show multi-plugin development capabilities. This plugin provides basic WordPress utilities and serves as a template for additional plugin development.
 * Version:     0.1.0
 * Author:      Okvir Platforma
 * Author URI:  mailto:okvir.platforma@gmail.com
 * License:     GPLv2 or later
 * Text Domain: example-second-plugin
 * Domain Path: /languages
 * Network:     false
 * Requires at least: 5.8
 * Tested up to: 6.4
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

class Example_Second_Plugin {
    
    const OPTION = 'example_second_plugin_settings';
    const NONCE = 'example_second_plugin_nonce';
    
    /** @var array */
    private $settings;
    
    public function __construct() {
        // Initialize settings
        $default_settings = [
            'feature_enabled' => true,
            'notification_email' => get_option('admin_email'),
            'log_level' => 'info'
        ];
        
        $this->settings = wp_parse_args(get_option(self::OPTION, []), $default_settings);
        
        // Register hooks
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'handle_actions']);
        register_activation_hook(__FILE__, [$this, 'on_activate']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivate']);
    }
    
    public function on_activate() {
        // Create plugin options if they don't exist
        if (!get_option(self::OPTION)) {
            add_option(self::OPTION, [
                'feature_enabled' => true,
                'notification_email' => get_option('admin_email'),
                'log_level' => 'info'
            ]);
        }
        
        // Log activation
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[Example Second Plugin] Plugin activated.');
        }
    }
    
    public function on_deactivate() {
        // Clean up any scheduled tasks
        wp_clear_scheduled_hook('example_second_plugin_daily_task');
        
        // Log deactivation
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[Example Second Plugin] Plugin deactivated.');
        }
    }
    
    public function menu() {
        add_submenu_page(
            'tools.php',
            'Example Second Plugin',
            'Second Plugin',
            'manage_options',
            'example-second-plugin',
            [$this, 'admin_page']
        );
    }
    
    public function handle_actions() {
        if (!current_user_can('manage_options')) return;
        
        // Handle settings update
        if (isset($_POST['example_second_plugin_settings']) && 
            wp_verify_nonce($_POST[self::NONCE], 'example_second_plugin_settings')) {
            
            $new_settings = [];
            
            // Sanitize and validate each setting
            $new_settings['feature_enabled'] = isset($_POST['feature_enabled']) ? 1 : 0;
            $new_settings['notification_email'] = sanitize_email($_POST['notification_email'] ?? get_option('admin_email'));
            $new_settings['log_level'] = sanitize_text_field($_POST['log_level'] ?? 'info');
            
            update_option(self::OPTION, $new_settings);
            $this->settings = $new_settings;
            
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved!</strong></p></div>';
            });
        }
        
        // Handle test action
        if (isset($_POST['run_test_action']) && 
            wp_verify_nonce($_POST[self::NONCE], 'example_second_plugin_test')) {
            
            $result = $this->run_test_action();
            
            if ($result['success']) {
                add_action('admin_notices', function() use ($result) {
                    echo '<div class="notice notice-success is-dismissible"><p><strong>Test completed!</strong> ' . esc_html($result['message']) . '</p></div>';
                });
            } else {
                add_action('admin_notices', function() use ($result) {
                    echo '<div class="notice notice-error is-dismissible"><p><strong>Test failed:</strong> ' . esc_html($result['message']) . '</p></div>';
                });
            }
        }
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div style="display: flex; gap: 20px; margin-top: 20px;">
                <!-- Settings Panel -->
                <div style="flex: 1; max-width: 600px;">
                    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                        <h2>Plugin Settings</h2>
                        
                        <form method="post">
                            <?php wp_nonce_field('example_second_plugin_settings', self::NONCE); ?>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Enable Feature</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="feature_enabled" 
                                                   <?php checked($this->settings['feature_enabled']); ?>>
                                            Enable the main plugin feature
                                        </label>
                                        <p class="description">When enabled, the plugin will perform its main functionality.</p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">Notification Email</th>
                                    <td>
                                        <input type="email" name="notification_email" class="regular-text"
                                               value="<?php echo esc_attr($this->settings['notification_email']); ?>">
                                        <p class="description">Email address for plugin notifications.</p>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <th scope="row">Log Level</th>
                                    <td>
                                        <select name="log_level">
                                            <option value="debug" <?php selected($this->settings['log_level'], 'debug'); ?>>Debug</option>
                                            <option value="info" <?php selected($this->settings['log_level'], 'info'); ?>>Info</option>
                                            <option value="warning" <?php selected($this->settings['log_level'], 'warning'); ?>>Warning</option>
                                            <option value="error" <?php selected($this->settings['log_level'], 'error'); ?>>Error</option>
                                        </select>
                                        <p class="description">Minimum log level to record.</p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p class="submit">
                                <input type="submit" name="example_second_plugin_settings" class="button button-primary" value="Save Settings">
                            </p>
                        </form>
                    </div>
                </div>
                
                <!-- Actions Panel -->
                <div style="flex: 0 0 300px;">
                    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                        <h3>Quick Actions</h3>
                        
                        <form method="post" style="margin-bottom: 20px;">
                            <?php wp_nonce_field('example_second_plugin_test', self::NONCE); ?>
                            <button type="submit" name="run_test_action" class="button button-secondary" style="width: 100%;">
                                Run Test Action
                            </button>
                            <p class="description">Execute a test action to verify plugin functionality.</p>
                        </form>
                        
                        <hr style="margin: 20px 0;">
                        
                        <h4>Plugin Status</h4>
                        <p><strong>Version:</strong> 0.1.0</p>
                        <p><strong>Status:</strong> <span style="color: green;">Active</span></p>
                        <p><strong>Feature Enabled:</strong> <?php echo $this->settings['feature_enabled'] ? '<span style="color: green;">Yes</span>' : '<span style="color: red;">No</span>'; ?></p>
                        
                        <hr style="margin: 20px 0;">
                        
                        <h4>Statistics</h4>
                        <?php $stats = $this->get_plugin_stats(); ?>
                        <p><strong>Test Runs:</strong> <?php echo esc_html($stats['test_runs']); ?></p>
                        <p><strong>Last Activity:</strong> <?php echo esc_html($stats['last_activity']); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Info Panel -->
            <div style="margin-top: 20px;">
                <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3>About This Plugin</h3>
                    <p>This is an example second plugin that demonstrates the multi-plugin development capabilities of the enhanced WordPress development environment.</p>
                    
                    <h4>Features:</h4>
                    <ul>
                        <li>✅ Basic settings management</li>
                        <li>✅ Test action execution</li>
                        <li>✅ Activity logging</li>
                        <li>✅ Statistics tracking</li>
                        <li>✅ Email notifications</li>
                    </ul>
                    
                    <h4>Development Notes:</h4>
                    <ul>
                        <li>This plugin serves as a template for creating additional plugins</li>
                        <li>It demonstrates proper WordPress coding standards</li>
                        <li>Includes activation/deactivation hooks</li>
                        <li>Follows security best practices</li>
                        <li>Provides a clean admin interface</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Run a test action
     */
    private function run_test_action() {
        if (!$this->settings['feature_enabled']) {
            return [
                'success' => false,
                'message' => 'Feature is disabled. Enable it in settings first.'
            ];
        }
        
        // Simulate some processing
        sleep(1);
        
        // Update test run counter
        $stats = get_option('example_second_plugin_stats', ['test_runs' => 0]);
        $stats['test_runs']++;
        $stats['last_activity'] = current_time('mysql');
        update_option('example_second_plugin_stats', $stats);
        
        // Log the action
        if ($this->settings['log_level'] === 'debug' || $this->settings['log_level'] === 'info') {
            error_log('[Example Second Plugin] Test action executed successfully.');
        }
        
        return [
            'success' => true,
            'message' => 'Test action completed successfully! Run #' . $stats['test_runs']
        ];
    }
    
    /**
     * Get plugin statistics
     */
    private function get_plugin_stats() {
        $stats = get_option('example_second_plugin_stats', [
            'test_runs' => 0,
            'last_activity' => 'Never'
        ]);
        
        if (is_string($stats['last_activity']) && $stats['last_activity'] !== 'Never') {
            $stats['last_activity'] = human_time_diff(strtotime($stats['last_activity']), current_time('timestamp')) . ' ago';
        }
        
        return $stats;
    }
}

// Initialize the plugin
$GLOBALS['example_second_plugin'] = new Example_Second_Plugin();

// WP-CLI command if available
if (defined('WP_CLI') && WP_CLI) {
    class Example_Second_Plugin_CLI_Command extends WP_CLI_Command {
        
        /**
         * Run the test action
         */
        public function test() {
            $plugin = $GLOBALS['example_second_plugin'] ?? null;
            if (!$plugin) {
                WP_CLI::error('Plugin not properly initialized');
                return;
            }
            
            WP_CLI::log('Running test action...');
            
            $result = $plugin->run_test_action();
            
            if ($result['success']) {
                WP_CLI::success($result['message']);
            } else {
                WP_CLI::error($result['message']);
            }
        }
        
        /**
         * Show plugin status
         */
        public function status() {
            $plugin = $GLOBALS['example_second_plugin'] ?? null;
            if (!$plugin) {
                WP_CLI::error('Plugin not properly initialized');
                return;
            }
            
            $stats = $plugin->get_plugin_stats();
            
            WP_CLI::log('Example Second Plugin Status:');
            WP_CLI::log('Version: 0.1.0');
            WP_CLI::log('Test Runs: ' . $stats['test_runs']);
            WP_CLI::log('Last Activity: ' . $stats['last_activity']);
            
            WP_CLI::success('Status check completed');
        }
    }
    
    WP_CLI::add_command('example-second-plugin', 'Example_Second_Plugin_CLI_Command');
}
