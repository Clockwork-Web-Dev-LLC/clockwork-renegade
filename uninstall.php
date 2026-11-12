<?php
/**
 * Clockwork Renegade Uninstall Cleanup
 *
 * Fired when the plugin is deleted via the WordPress admin. The plugin
 * itself is not loaded at this point, so its classes are autoloaded here;
 * the list of what to remove lives in ClockworkRenegade\Support\Uninstaller.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (! defined('CLOCKWORK_RENEGADE_DIR')) {
    define('CLOCKWORK_RENEGADE_DIR', __DIR__);
}

spl_autoload_register(function (string $class): void {
    $prefix = 'ClockworkRenegade\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $path = CLOCKWORK_RENEGADE_DIR . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// Clockwork Companion stores some data under the same names (2FA user meta,
// update exceptions, white-label options, the hourly resource table); leave
// those alone while it is running on this site.
ClockworkRenegade\Support\Uninstaller::run(! defined('CLOCKWORK_COMPANION_VERSION'));
