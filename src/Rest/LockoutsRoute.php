<?php

namespace ClockworkRenegade\Rest;

use ClockworkRenegade\Auth\HmacVerifier;
use ClockworkRenegade\Gatekeeper\Settings as GatekeeperSettings;
use ClockworkRenegade\Gatekeeper\Store as GatekeeperStore;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET    /wp-json/clockwork/v1/lockouts        — list active lockouts.
 * DELETE /wp-json/clockwork/v1/lockouts        — clear all lockouts.
 * DELETE /wp-json/clockwork/v1/lockouts?ip=x  — clear one IP.
 *
 * Supports native Gatekeeper store, with fallback to Limit Login Attempts Reloaded (LLAR)
 * during fleet migration. DELETE clears both native and legacy LLAR stores.
 *
 * On multisite the LLAR fallback reads every blog plus LLAR's network-wide
 * option, matching what DELETE clears (Gatekeeper's own store already
 * spans the network). Every list goes through ReportedBlocks before it
 * leaves the site, because Clockwork turns it into IP bans.
 */
class LockoutsRoute
{
    public function register(): void
    {
        register_rest_route(CLOCKWORK_RENEGADE_NAMESPACE, '/lockouts', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'handle'],
                'permission_callback' => [HmacVerifier::class, 'verify'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'handleDelete'],
                'permission_callback' => [HmacVerifier::class, 'verify'],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (GatekeeperSettings::enabled()) {
            return $this->respond(GatekeeperStore::activeLockouts());
        }

        $native = GatekeeperStore::activeLockouts();
        if (! empty($native)) {
            return $this->respond($native);
        }

        // Fall back to legacy LLAR read
        $byIp = [];
        $siteIds = is_multisite() ? array_map('intval', (array) get_sites(['fields' => 'ids', 'number' => 0])) : [null];

        foreach ($siteIds as $siteId) {
            if ($siteId !== null) {
                switch_to_blog($siteId);
            }

            global $wpdb;
            foreach ($this->fromTable($wpdb->prefix . 'limit_login_lockouts') as $row) {
                $byIp[$row['ip']] ??= $row;
            }
            // Option-row entries fill in only IPs the table didn't already supply.
            foreach ($this->fromOptionRow($wpdb->options) as $row) {
                $byIp[$row['ip']] ??= $row;
            }

            if ($siteId !== null) {
                restore_current_blog();
            }
        }

        if (is_multisite()) {
            foreach ($this->fromNetworkOption() as $row) {
                $byIp[$row['ip']] ??= $row;
            }
        }

        return $this->respond(array_values($byIp));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function respond(array $rows): WP_REST_Response
    {
        $filtered = ReportedBlocks::filter($rows);

        return new WP_REST_Response([
            'ok' => true,
            'lockouts' => $filtered['rows'],
            'truncated' => $filtered['truncated'],
        ]);
    }

    /**
     * LLAR "Network/Site Wide" lockouts from wp_sitemeta, read directly to
     * bypass a possibly stale object cache.
     *
     * @return array<int, array{ip: string, unlock_at: ?string, source_table: string}>
     */
    private function fromNetworkOption(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = 'limit_login_lockouts' LIMIT 1",
            get_current_network_id()
        ));
        $lockouts = is_string($raw) && $raw !== '' ? @unserialize($raw, ['allowed_classes' => false]) : [];
        if (! is_array($lockouts)) {
            return [];
        }

        $now = time();
        $out = [];
        foreach ($lockouts as $ip => $unlockTs) {
            $ip = trim((string) $ip);
            $unlockTs = (int) $unlockTs;
            if (! filter_var($ip, FILTER_VALIDATE_IP) || ($unlockTs > 0 && $unlockTs <= $now)) {
                continue;
            }
            $out[] = [
                'ip' => $ip,
                'unlock_at' => $unlockTs > 0 ? gmdate('c', $unlockTs) : null,
                'source_table' => $wpdb->sitemeta . ':limit_login_lockouts',
            ];
        }

        return $out;
    }

    public function handleDelete(WP_REST_Request $request): WP_REST_Response
    {
        $ip = $request->get_param('ip');

        if ($ip !== null) {
            $ip = trim((string) $ip);
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'Invalid IP address.'], 400);
            }

            GatekeeperStore::unlockIp($ip);

            return new WP_REST_Response([
                'ok' => true,
                'unlocked' => $ip,
            ]);
        }

        $cleared = GatekeeperStore::unlockAll();

        return new WP_REST_Response([
            'ok' => true,
            'cleared' => $cleared,
        ]);
    }

    /**
     * @return array<int, array{ip: string, unlock_at: ?string, source_table: string}>
     */
    private function fromTable(string $tableName): array
    {
        global $wpdb;

        $existsSql = $wpdb->prepare('SHOW TABLES LIKE %s', $tableName);
        $existing = $wpdb->get_var($existsSql);
        if ($existing !== $tableName) {
            return [];
        }

        $wpdb->suppress_errors(true);
        $sqlV2 = "SELECT ip, lockout_end AS unlock_at FROM `{$tableName}` "
            . 'WHERE lockout_end IS NULL OR lockout_end > NOW()';
        $rows = $wpdb->get_results($sqlV2, ARRAY_A);

        if ($rows === null) {
            $sqlV1 = "SELECT ip, FROM_UNIXTIME(`unlock`) AS unlock_at FROM `{$tableName}` "
                . 'WHERE `unlock` IS NULL OR `unlock` > UNIX_TIMESTAMP()';
            $rows = $wpdb->get_results($sqlV1, ARRAY_A);
        }
        $wpdb->suppress_errors(false);

        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $ip = trim((string) ($row['ip'] ?? ''));
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $unlockRaw = $row['unlock_at'] ?? null;
            $unlockAt = $this->mysqlDatetimeToIso8601($unlockRaw);

            $out[] = [
                'ip' => $ip,
                'unlock_at' => $unlockAt,
                'source_table' => $tableName,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{ip: string, unlock_at: ?string, source_table: string}>
     */
    private function fromOptionRow(string $optionsTable): array
    {
        global $wpdb;

        $wpdb->suppress_errors(true);
        $sql = $wpdb->prepare(
            "SELECT option_value FROM `{$optionsTable}` WHERE option_name = %s LIMIT 1",
            'limit_login_lockouts'
        );
        $value = $wpdb->get_var($sql);
        $wpdb->suppress_errors(false);

        if (! is_string($value) || $value === '') {
            return [];
        }

        $unserialized = @unserialize($value, ['allowed_classes' => false]);
        if (! is_array($unserialized)) {
            return [];
        }

        $now = time();
        $sourceLabel = $optionsTable . ':limit_login_lockouts';
        $out = [];

        foreach ($unserialized as $ip => $unlockTs) {
            $ip = trim((string) $ip);
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $unlockTs = (int) $unlockTs;
            if ($unlockTs > 0 && $unlockTs <= $now) {
                continue;
            }

            $out[] = [
                'ip' => $ip,
                'unlock_at' => $unlockTs > 0 ? gmdate('c', $unlockTs) : null,
                'source_table' => $sourceLabel,
            ];
        }

        return $out;
    }

    private function mysqlDatetimeToIso8601(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        $ts = strtotime((string) $value . ' UTC');
        if ($ts === false) {
            return null;
        }

        return gmdate('c', $ts);
    }
}
