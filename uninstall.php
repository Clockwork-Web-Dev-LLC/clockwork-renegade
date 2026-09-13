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
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('clockwork_renegade_').'%',
            $wpdb->esc_like('_transient_clockwork_renegade_').'%',
            $wpdb->esc_like('_transient_timeout_clockwork_renegade_').'%',
            $wpdb->esc_like('clockwork_sso_').'%'
        )
    );

    // Drop custom tables (names are hardcoded suffixes on this site's prefix).
    $tables = [
        $wpdb->prefix.'clockwork_renegade_action_log',
        $wpdb->prefix.'clockwork_renegade_auth_failures',
        $wpdb->prefix.'clockwork_renegade_resource_samples',
    ];

    foreach ($tables as $table) {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            continue;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name validated above.
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }
}

if (is_multisite()) {
    $clockwork_site_ids = get_sites(['fields' => 'ids']);
    foreach ($clockwork_site_ids as $clockwork_site_id) {
        switch_to_blog($clockwork_site_id);
        clockwork_renegade_uninstall_site();
        restore_current_blog();
    }
} else {
    clockwork_renegade_uninstall_site();
}
