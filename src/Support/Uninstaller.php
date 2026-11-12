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
 * clockwork_* transients, the hourly resource table). They are only
 * removed when Companion is not running on the site (uninstall.php checks),
 * so uninstalling one plugin cannot wipe the other's settings.
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
            MaintenanceGuard::OPTION_KEY,
            RestoreState::OPTION_KEY,
            WhiteLabel::OPTION_KEY,
            GatekeeperSettings::OPTION,
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

    /** @return list<string> */
    public static function cronHooks(): array
    {
        return ['clockwork_gatekeeper_prune'];
    }

    /**
     * Tables of the current site (and, on multisite, the network lockout
     * table) that only this plugin uses.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        return array_values(array_unique(array_merge(
            [
                ActionLogSchema::tableName(),
                AuthAuditSchema::tableName(),
                GatekeeperSchema::tableName(),
            ],
            GatekeeperSchema::legacySiteTables()
        )));
    }

    /**
     * Tables Companion creates under the same name.
     *
     * @return list<string>
     */
    public static function sharedTables(): array
    {
        return [ResourceSchema::tableName()];
    }

    /**
     * True when uninstall removes the option $name.
     */
    public static function coversOption(string $name): bool
    {
        if (in_array($name, self::optionNames(), true)) {
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
     *                           Companion is running.
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

            foreach (self::optionNames() as $option) {
                delete_site_option($option);
            }
            $patterns = $withShared ? array_merge(self::optionPatterns(), self::sharedOptionPatterns()) : self::optionPatterns();
            self::deleteLike((string) ($wpdb->sitemeta ?? $wpdb->base_prefix.'sitemeta'), 'meta_key', $patterns);
            self::dropTable(GatekeeperSchema::tableName());
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

        foreach (self::cronHooks() as $hook) {
            self::clearCron($hook);
        }

        foreach (self::optionNames() as $option) {
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
     */
    public static function deactivate(): void
    {
        if (is_multisite()) {
            foreach ((array) get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                foreach (self::cronHooks() as $hook) {
                    self::clearCron($hook);
                }
                restore_current_blog();
            }

            return;
        }

        foreach (self::cronHooks() as $hook) {
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
