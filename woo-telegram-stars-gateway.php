<?php
/**
 * Plugin Name: WooCommerce Telegram Stars Gateway
 * Plugin URI: https://github.com/Aliasgharhi/woo-telegram-gateway
 * Description: Accept Telegram Stars payments in your WooCommerce store
 * Version: 1.0.5
 * Author: Aliasgharhi
 * Author URI: https://github.com/Aliasgharhi/
 * Text Domain: woo-telegram-stars-gateway
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * WooCommerce: true
 * Requires WooCommerce: 5.0
 * Requires WooCommerce HPOS: true
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('WTSG_VERSION', '1.0.5');
define('WTSG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WTSG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WTSG_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'WooTelegramStars\\';
    $base_dir = WTSG_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Initialize the plugin
function wtsg_init() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            ?>
            <div class="error">
                <p><?php echo esc_html__('WooCommerce Telegram Stars Gateway requires WooCommerce to be installed and active.', 'woo-telegram-stars-gateway'); ?></p>
            </div>
            <?php
        });
        return;
    }

    // Load plugin text domain
    load_plugin_textdomain('woo-telegram-stars-gateway', false, dirname(WTSG_PLUGIN_BASENAME) . '/languages');

    // Initialize the gateway
    add_filter('woocommerce_payment_gateways', function($gateways) {
        $gateways[] = 'WooTelegramStars\Gateway\TelegramStarsGateway';
        return $gateways;
    });
}
add_action('plugins_loaded', 'wtsg_init', 0);

// Activation hook
register_activation_hook(__FILE__, 'wtsg_activate');

/**
 * Plugin activation function
 */
function wtsg_activate() {
    // Create necessary database tables
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'wtsg_payments';

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        order_id bigint(20) NOT NULL,
        telegram_payment_id varchar(255) NOT NULL,
        stars_amount int(11) NOT NULL,
        status varchar(50) NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY order_id (order_id),
        KEY telegram_payment_id (telegram_payment_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Add plugin version to the database
    update_option('wtsg_version', WTSG_VERSION);
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'wtsg_deactivate');

/**
 * Plugin deactivation function
 */
function wtsg_deactivate() {
    // No specific action needed on deactivation
}

// Uninstall hook - Note: For a complete uninstallation, create an uninstall.php file
// register_uninstall_hook(__FILE__, 'wtsg_uninstall');

/**
 * Check if we need to update
 */
function wtsg_check_version() {
    $installed_version = get_option('wtsg_version', '0');
    
    if (version_compare($installed_version, WTSG_VERSION, '<')) {
        wtsg_activate();
    }
}
add_action('admin_init', 'wtsg_check_version'); 