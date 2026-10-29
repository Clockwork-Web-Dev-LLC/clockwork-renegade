<?php
/**
 * Plugin Name:       Clockwork Renegade
 * Plugin URI:        https://clockworkcontrol.com
 * Description:       Connects your WordPress site to Clockwork Control for centralized monitoring, uptime verification, updates, and security telemetry.
 * Version:           1.1.2
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      8.0
 * Author:            Clockwork Web Dev, LLC
 * Author URI:        https://clockworkcontrol.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       clockwork-renegade
 * Update URI:         https://github.com/Clockwork-Web-Dev-LLC/clockwork-renegade
 * Domain Path:       /languages
 */

if (! defined('ABSPATH')) {
    exit;
}

define('CLOCKWORK_RENEGADE_VERSION', '1.1.2');
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (! isset($_GET['activate-multi']) && current_user_can('manage_options')) {
            wp_safe_redirect(admin_url('admin.php?page=clockwork-connection'));
            exit;
        }
    }
});

add_action('plugins_loaded', function (): void {
    // WordPress.org automatically loads translations for community plugins since WP 4.6+.
    (new ClockworkRenegade\Plugin())->boot();
}, 5);
