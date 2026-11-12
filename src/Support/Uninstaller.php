<?php

namespace ClockworkRenegade\Support;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removes this plugin's own tables and rows; table names come from the schema classes and are validated.

use ClockworkRenegade\ActionLog\Schema as ActionLogSchema;
use ClockworkRenegade\AuthAudit\Schema as AuthAuditSchema;
use ClockworkRenegade\Backup\Paths;
use ClockworkRenegade\Gatekeeper\Schema as GatekeeperSchema;
use ClockworkRenegade\Gatekeeper\Settings as GatekeeperSettings;
use ClockworkRenegade\Resource\Schema as ResourceSchema;
use ClockworkRenegade\Auth\Secret;
use ClockworkRenegade\Backup\RestoreState;
use ClockworkRenegade\Updates\InstallState;
use ClockworkRenegade\Maintenance\MaintenanceGuard;
use ClockworkRenegade\WhiteLabel\WhiteLabel;

/**
 * Removes everything the plugin stores: tables, options, transients, user
 * meta (2FA secrets), cron events and backup work files.
 *
 * Table names come from the schema classes, so a renamed or added table is
 * covered without touching this class; tests/UninstallerTest.php fails when a
 * table, option, user-meta key or cron hook used in src/ is not covered.
 *
 * Some names are shared with Clockwork Companion (2FA user meta, the
 * update-exception and white-label options, SSO nonces, generic
 * clockwork_* transients, the hourly resource table, and Gatekeeper's
 * lockout table, policy option and prune event). They are only removed when
 * Companion is not installed on the site (companionInstalled(), checked by
 * uninstall.php), so uninstalling one plugin cannot wipe the other's data —
 * including when Companion is installed but switched off at that moment.
 */
class Uninstaller
{
    /**
     * Options removed by name through delete_option(), which also clears
     * them from a persistent object cache. Everything else matching the
     * patterns below is removed with one query.
     *
     * @return list<string>
     */
    public static function optionNames(): array
    {
        return [
            Secret::OPTION_KEY,
            Secret::PENDING_OPTION_KEY,
            InstallState::OPTION,
            MaintenanceGuard::OPTION_KEY,
            RestoreState::OPTION_KEY,
            WhiteLabel::OPTION_KEY,
            GatekeeperSchema::VERSION_OPTION,
            ActionLogSchema::VERSION_OPTION,
            AuthAuditSchema::VERSION_OPTION,
            ResourceSchema::VERSION_OPTION,
        ];
    }

    /** LIKE patterns (escaped) of options owned by this plugin alone. */
    public static function optionPatterns(): array
    {
        return [
            'clockwork\\_renegade\\_%',
            '\\_transient\\_clockwork\\_renegade\\_%',
            '\\_transient\\_timeout\\_clockwork\\_renegade\\_%',
            '\\_site\\_transient\\_clockwork\\_renegade\\_%',
            '\\_site\\_transient\\_timeout\\_clockwork\\_renegade\\_%',
        ];
    }

    /**
     * Options removed by name that Companion stores under the same name.
     *
     * @return list<string>
     */
    public static function sharedOptionNames(): array
    {
        return [GatekeeperSettings::OPTION];
    }

    /** LIKE patterns of options Companion uses under the same names. */
    public static function sharedOptionPatterns(): array
    {
        return [
            'clockwork\\_update\\_exceptions',
            'clockwork\\_white\\_label',
            'clockwork\\_whitelabel\\_%',
            'clockwork\\_sso\\_%',
            'clockwork\\_hmac\\_used\\_%',
            '\\_transient\\_clockwork\\_%',
            '\\_transient\\_timeout\\_clockwork\\_%',
        ];
    }

    /** LIKE pattern of user meta keys (shared with Companion). */
    public const USER_META_PATTERN = '\\_clockwork\\_2fa\\_%';

    /**
     * Cron hooks only this plugin schedules.
     *
     * @return list<string>
     */
    public static function cronHooks(): array
    {
        return [];
    }

    /**
     * Cron hooks Companion schedules under the same name (its Gatekeeper
     * prunes the shared lockout table).
     *
     * @return list<string>
     */
    public static function sharedCronHooks(): array
    {
        return ['clockwork_gatekeeper_prune'];
    }

    /**
     * Tables of the current site that only this plugin uses.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        return [
            ActionLogSchema::tableName(),
            AuthAuditSchema::tableName(),
        ];
    }

    /**
     * Tables Companion creates under the same name: the hourly resource
     * table, and Gatekeeper's lockout table (on multisite the network table
     * plus any per-site tables an older version left).
     *
     * @return list<string>
     */
    public static function sharedTables(): array
    {
        return array_values(array_unique(array_merge(
            [
                ResourceSchema::tableName(),
                GatekeeperSchema::tableName(),
            ],
            GatekeeperSchema::legacySiteTables()
        )));
    }

    /**
     * Whether Clockwork Companion is installed on this site: in the plugins
     * list (active or not, on any site of a network) or as a must-use
     * plugin. (uninstall.php also treats a loaded Companion as installed.)
     * Companion has no uninstaller of its own, so its options outlive it and
     * are deliberately not used as the signal: a site that moved from
     * Companion to this plugin would otherwise never get the shared data
     * cleaned up.
     */
    public static function companionInstalled(): bool
    {
        if (defined('WPMU_PLUGIN_DIR') && is_file(WPMU_PLUGIN_DIR . '/clockwork-companion.php')) {
            return true;
        }

        if (! function_exists('get_plugins') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (function_exists('get_plugins')) {
            foreach (array_keys((array) get_plugins()) as $file) {
                if (basename((string) $file) === 'clockwork-companion.php') {
                    return true;
                }
            }

            return false;
        }

        // No plugin API: look for the loader in any plugin folder.
        return defined('WP_PLUGIN_DIR') && (glob(WP_PLUGIN_DIR . '/*/clockwork-companion.php') ?: []) !== [];
    }

    /**
     * True when uninstall removes the option $name.
     */
    public static function coversOption(string $name): bool
    {
        if (in_array($name, array_merge(self::optionNames(), self::sharedOptionNames()), true)) {
            return true;
        }
        foreach (array_merge(self::optionPatterns(), self::sharedOptionPatterns()) as $pattern) {
            if (self::likeMatches($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    public static function likeMatches(string $pattern, string $value): bool
    {
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $regex .= preg_quote($pattern[++$i], '/');
            } elseif ($char === '%') {
                $regex .= '.*';
            } elseif ($char === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($char, '/');
            }
        }

        return preg_match('/^'.$regex.'$/s', $value) === 1;
    }

    /**
     * Full uninstall: every site of a network (no 100-site cap), then the
     * network-wide data.
     *
     * @param  bool  $withShared Also remove data stored under names Companion
     *                           shares; uninstall.php passes false while
     *                           Companion is installed.
     */
    public static function run(bool $withShared = true): void
    {
        global $wpdb;

        if (is_multisite()) {
            foreach ((array) get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::uninstallSite($withShared);
                restore_current_blog();
            }

            foreach ($withShared ? array_merge(self::optionNames(), self::sharedOptionNames()) : self::optionNames() as $option) {
                delete_site_option($option);
            }
            $patterns = $withShared ? array_merge(self::optionPatterns(), self::sharedOptionPatterns()) : self::optionPatterns();
            self::deleteLike((string) ($wpdb->sitemeta ?? $wpdb->base_prefix.'sitemeta'), 'meta_key', $patterns);
            if ($withShared) {
                self::dropTable(GatekeeperSchema::tableName());
            }
        } else {
            self::uninstallSite($withShared);
        }

        if ($withShared) {
            // usermeta is one table for the whole network.
            self::deleteLike((string) ($wpdb->usermeta ?? ($wpdb->base_prefix ?? $wpdb->prefix).'usermeta'), 'meta_key', [self::USER_META_PATTERN]);
        }
    }

    public static function uninstallSite(bool $withShared = true): void
    {
        global $wpdb;

        foreach ($withShared ? array_merge(self::cronHooks(), self::sharedCronHooks()) : self::cronHooks() as $hook) {
            self::clearCron($hook);
        }

        foreach ($withShared ? array_merge(self::optionNames(), self::sharedOptionNames()) : self::optionNames() as $option) {
            delete_option($option);
        }
        $patterns = $withShared ? array_merge(self::optionPatterns(), self::sharedOptionPatterns()) : self::optionPatterns();
        self::deleteLike((string) ($wpdb->options ?? $wpdb->prefix.'options'), 'option_name', $patterns);

        foreach ($withShared ? array_merge(self::tables(), self::sharedTables()) : self::tables() as $table) {
            self::dropTable($table);
        }

        foreach (Paths::existingStagingDirs() as $dir) {
            self::removeWorkFiles($dir);
        }
    }

    /**
     * Deactivation: stop the scheduled prune, on every site of a network.
     * The shared hook is cleared too: Companion, if it is running, schedules
     * it again on its next request.
     */
    public static function deactivate(): void
    {
        if (is_multisite()) {
            foreach ((array) get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                foreach (array_merge(self::cronHooks(), self::sharedCronHooks()) as $hook) {
                    self::clearCron($hook);
                }
                restore_current_blog();
            }

            return;
        }

        foreach (array_merge(self::cronHooks(), self::sharedCronHooks()) as $hook) {
            self::clearCron($hook);
        }
    }

    private static function clearCron(string $hook): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook($hook);
        }
    }

    /**
     * @param  list<string>  $patterns Escaped LIKE patterns.
     */
    private static function deleteLike(string $table, string $column, array $patterns): void
    {
        global $wpdb;

        if ($patterns === [] || ! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return;
        }

        $where = implode(' OR ', array_fill(0, count($patterns), "{$column} LIKE %s"));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE {$where}", ...$patterns));
    }

    private static function dropTable(string $table): void
    {
        global $wpdb;

        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return;
        }
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }

    /**
     * Remove this plugin's work files from a staging directory, then the
     * directory itself if nothing else is in it (a CLOCKWORK_BACKUP_DIR may
     * be shared with other files).
     */
    private static function removeWorkFiles(string $dir): void
    {
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if (preg_match('/^(db-|backup-|manifest-|restore-)/', $item) || in_array($item, ['index.php', 'index.html', '.htaccess', 'web.config'], true)) {
                Paths::rmrf($dir.'/'.$item);
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- uninstall cleanup of our own directory.
        @rmdir($dir);
    }
}
