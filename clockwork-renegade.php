<?php
/**
 * Plugin Name:       Clockwork Renegade
 * Plugin URI:        https://clockworkcontrol.com
 * Description:       Connects your WordPress site to Clockwork Control for centralized monitoring, uptime verification, updates, and security telemetry.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Tested up to:      6.7
 * Requires PHP:      8.0
 * Author:            Clockwork Web Dev, LLC
 * Author URI:        https://clockworkcontrol.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       clockwork-renegade
 * Domain Path:       /languages
 */

if (! defined('ABSPATH')) {
    exit;
}

define('CLOCKWORK_RENEGADE_VERSION', '1.0.0');
if (! defined('CLOCKWORK_RENEGADE_DIR')) {
    define('CLOCKWORK_RENEGADE_DIR', __DIR__);
}
if (! defined('CLOCKWORK_RENEGADE_FILE')) {
    define('CLOCKWORK_RENEGADE_FILE', __FILE__);
}
define('CLOCKWORK_RENEGADE_NAMESPACE', 'clockwork-renegade/v1');

if (! defined('CLOCKWORK_SUPPORT_SITE_URL')) {
    define('CLOCKWORK_SUPPORT_SITE_URL', '');
}

spl_autoload_register(function (string $class): void {
    $prefix = 'ClockworkRenegade\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = CLOCKWORK_RENEGADE_DIR . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

register_activation_hook(__FILE__, function (): void {
    set_transient('clockwork_renegade_activation_redirect', true, 60);
});

add_action('admin_init', function (): void {
    if (get_transient('clockwork_renegade_activation_redirect')) {
        delete_transient('clockwork_renegade_activation_redirect');
        if (! isset($_GET['activate-multi']) && current_user_can('manage_options')) {
            wp_safe_redirect(admin_url('tools.php?page=clockwork-renegade-connection'));
            exit;
        }
    }
});

add_action('plugins_loaded', function (): void {
    load_plugin_textdomain('clockwork-renegade', false, dirname(plugin_basename(__FILE__)) . '/languages');
    (new ClockworkRenegade\Plugin())->boot();
}, 5);
