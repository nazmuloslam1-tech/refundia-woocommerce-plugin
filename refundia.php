<?php
/**
 * Plugin Name: Refundia — Returns Management
 * Plugin URI: https://refundia-dashboard-plus-api.vercel.app
 * Description: Automated returns, refunds, and exchanges management for WooCommerce. Integrates with the Refundia dashboard for Spanish merchants.
 * Version: 0.5.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Refundia
 * Author URI: https://refundia-dashboard-plus-api.vercel.app
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: refundia
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 */

defined('ABSPATH') || exit;

define('REFUNDIA_VERSION', '0.5.0');
define('REFUNDIA_PLUGIN_FILE', __FILE__);
define('REFUNDIA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('REFUNDIA_API_BASE', 'https://refundia-dashboard-plus-api.vercel.app/api');

// Autoload classes from /includes
spl_autoload_register(function ($class) {
    $prefix = 'Refundia\\';
    if (strpos($class, $prefix) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = REFUNDIA_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require $file;
});

// Bootstrap
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>Refundia requires WooCommerce to be installed and active.</p></div>';
        });
        return;
    }
    new Refundia\Plugin();
});

// Activation / deactivation hooks
register_activation_hook(__FILE__, ['Refundia\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['Refundia\\Deactivator', 'deactivate']);
