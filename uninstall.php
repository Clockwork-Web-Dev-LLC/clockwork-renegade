<?php
/**
 * Clockwork Renegade Uninstall Cleanup
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Removes all persisted configuration, secrets, transient caches, and custom tables.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function clockwork_renegade_uninstall_site(): void
{
    global $wpdb;

    $known_options = [
        'clockwork_renegade_secret',
        'clockwork_renegade_branding',
        'clockwork_renegade_detected_forms',
        'clockwork_renegade_form_test_subscriptions',
        'clockwork_renegade_form_test_results',
        'clockwork_renegade_last_contact_at',
        'clockwork_renegade_backups_report',
        'clockwork_renegade_traffic_report',
        'clockwork_renegade_pressable_security_summary',
        'clockwork_renegade_auth_failures_schema_version',
        'clockwork_renegade_action_log_schema_version',
        'clockwork_renegade_maintenance_mode',
        'clockwork_renegade_resource_sampler_enabled',
        'clockwork_renegade_resource_schema_version',
        'clockwork_renegade_restore_state',
        'clockwork_renegade_client_notifications',
        'clockwork_renegade_activation_redirect',
    ];

    foreach ($known_options as $option) {
        delete_option($option);
        delete_site_option($option);
    }

    // Wildcard cleanup for any dynamic options or transients
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'clockwork\_renegade\_%' OR option_name LIKE '\_transient\_clockwork\_renegade\_%' OR option_name LIKE '\_transient\_timeout\_clockwork\_renegade\_%' OR option_name LIKE 'clockwork\_sso\_%'");

    // Drop custom tables
    $tables = [
        $wpdb->prefix . 'clockwork_renegade_action_log',
        $wpdb->prefix . 'clockwork_renegade_auth_failures',
        $wpdb->prefix . 'clockwork_renegade_resource_samples',
    ];

    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }
}

if (is_multisite()) {
    $site_ids = get_sites(['fields' => 'ids']);
    foreach ($site_ids as $site_id) {
        switch_to_blog($site_id);
        clockwork_renegade_uninstall_site();
        restore_current_blog();
    }
} else {
    clockwork_renegade_uninstall_site();
}
