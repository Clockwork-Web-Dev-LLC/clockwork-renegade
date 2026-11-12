<?php

namespace ClockworkRenegade\Tests;

use ClockworkRenegade\Support\Uninstaller;
use PHPUnit\Framework\TestCase;

/**
 * Keeps uninstall from drifting behind the code: every option, transient,
 * user-meta key, cron hook and table referenced in src/ must be removed by
 * Uninstaller. Add a new one and this fails until uninstall covers it.
 */
class UninstallerTest extends TestCase
{
    /** Core options the plugin reads or updates but does not own. */
    private const NOT_OURS = ['active_plugins', 'limit_login_lockouts', 'limit_login_retries', 'limit_login_retries_valid', 'wordfence_ls_version'];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wpdb'] = new \wpdb();
        $GLOBALS['wp_test_cleared_cron'] = [];
    }

    /** @return array<string, string> file => contents */
    private static function sources(): array
    {
        $files = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $file) {
            if ($file->getExtension() === 'php') {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    public function testEveryStoredOptionIsRemoved(): void
    {
        $names = [];
        foreach (self::sources() as $code) {
            // Constants holding option names, option prefixes and transient keys.
            preg_match_all("/const\s+(\w*(?:OPTION|OPTION_KEY|OPTION_ENABLED|OPTION_PREFIX|CACHE_KEY))\s*=\s*'([^']+)'/", $code, $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                $names[] = $match[2] . (str_ends_with($match[1], 'PREFIX') ? 'x' : '');
            }
            preg_match_all("/const\s+\w*TRANSIENT_PREFIX\s*=\s*'([^']+)'/", $code, $t);
            foreach ($t[1] as $prefix) {
                $names[] = '_transient_' . $prefix . 'x';
            }
            preg_match_all("/const\s+\w*TRANSIENT\s*=\s*'([^']+)'/", $code, $tc);
            foreach ($tc[1] as $transient) {
                $names[] = '_transient_' . $transient;
            }
            preg_match_all("/(?:update_option|add_option)\(\s*'([^']+)'/", $code, $l);
            $names = array_merge($names, $l[1]);
            preg_match_all("/(?:set_transient)\(\s*'([^']+)'/", $code, $tl);
            foreach ($tl[1] as $transient) {
                $names[] = '_transient_' . $transient;
            }
        }
        // Rows HmacVerifier and OperationLock write with computed names.
        $names[] = 'clockwork_hmac_used_0123abcd';
        $names[] = '_transient_clockwork_lock_backup_restore';
        $names[] = '_transient_clockwork_hmac_fail_0123';

        $names = array_diff(array_unique($names), self::NOT_OURS);
        $this->assertNotEmpty($names);

        $missing = array_values(array_filter($names, fn (string $n): bool => ! Uninstaller::coversOption($n)));
        $this->assertSame([], $missing, 'Options not removed on uninstall');
    }

    public function testEveryUserMetaKeyIsRemoved(): void
    {
        $missing = [];
        foreach (self::sources() as $code) {
            preg_match_all("/const\s+META_\w+\s*=\s*'([^']+)'/", $code, $m);
            foreach ($m[1] as $key) {
                if (! Uninstaller::likeMatches(Uninstaller::USER_META_PATTERN, $key)) {
                    $missing[] = $key;
                }
            }
        }

        $this->assertSame([], $missing);
    }

    public function testEveryCronHookIsCleared(): void
    {
        $hooks = [];
        foreach (self::sources() as $code) {
            preg_match_all("/wp_schedule_(?:single_)?event\([^;]*?'([a-z0-9_]+)'\s*\)/", $code, $m);
            $hooks = array_merge($hooks, $m[1]);
        }

        $this->assertNotEmpty($hooks);
        $this->assertSame([], array_values(array_diff($hooks, Uninstaller::cronHooks())));
    }

    public function testEverySchemaTableIsDropped(): void
    {
        $covered = array_merge(Uninstaller::tables(), Uninstaller::sharedTables());
        $missing = [];
        foreach (array_keys(self::sources()) as $file) {
            if (basename($file) !== 'Schema.php') {
                continue;
            }
            $class = 'ClockworkRenegade\\' . str_replace(['/', '.php'], ['\\', ''], substr($file, strlen(dirname(__DIR__) . '/src/')));
            $table = $class::tableName();
            if (! in_array($table, $covered, true)) {
                $missing[] = $table;
            }
        }

        $this->assertSame([], $missing);
    }

    /**
     * The old uninstall.php dropped a table that never existed and left the
     * real resource and lockout tables, 2FA secrets and the prune event.
     */
    public function testUninstallRemovesTheRealTablesSecretsAndCron(): void
    {
        update_option('clockwork_gatekeeper_settings', ['enabled' => true]);
        update_option('clockwork_renegade_secret', 'secret');

        Uninstaller::run();

        $queries = implode("\n", $GLOBALS['wpdb']->queries);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `wp_clockwork_resource_hourly`', $queries);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `wp_clockwork_lockouts`', $queries);
        $this->assertStringContainsString("DELETE FROM `wp_usermeta` WHERE meta_key LIKE '\\_clockwork\\_2fa\\_%'", $queries);
        $this->assertStringNotContainsString('resource_samples', $queries);
        $this->assertFalse(get_option('clockwork_gatekeeper_settings'));
        $this->assertContains('clockwork_gatekeeper_prune', $GLOBALS['wp_test_cleared_cron']);
    }

    public function testMultisiteUninstallVisitsEverySiteWithoutTheDefaultCap(): void
    {
        $GLOBALS['wp_test_is_multisite'] = true;
        $GLOBALS['wp_test_site_ids'] = [1, 2, 3];
        $GLOBALS['wp_test_get_sites_args'] = [];

        try {
            Uninstaller::run();
        } finally {
            $GLOBALS['wp_test_is_multisite'] = false;
            unset($GLOBALS['wp_test_site_ids']);
        }

        $this->assertSame(0, $GLOBALS['wp_test_get_sites_args'][0]['number'] ?? null);
        $this->assertCount(3, array_keys($GLOBALS['wp_test_cleared_cron'], 'clockwork_gatekeeper_prune', true));
    }

    public function testDeactivationClearsTheScheduledPrune(): void
    {
        Uninstaller::deactivate();

        $this->assertContains('clockwork_gatekeeper_prune', $GLOBALS['wp_test_cleared_cron']);
    }

    public function testDataSharedWithCompanionIsKeptWhileCompanionRuns(): void
    {
        Uninstaller::run(false);

        $queries = implode("\n", $GLOBALS['wpdb']->queries);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `wp_clockwork_lockouts`', $queries);
        $this->assertStringNotContainsString('wp_usermeta', $queries);
        $this->assertStringNotContainsString('clockwork_resource_hourly', $queries);
        $this->assertStringNotContainsString('clockwork\\_update\\_exceptions', $queries);
    }

    /** Data the auth / 2FA / updates code stores (secret rotation, install state, 2FA lockout). */
    public function testRotationInstallAndTwoFactorStateIsRemoved(): void
    {
        foreach (['clockwork_renegade_secret_pending', 'clockwork_renegade_install_state', '_transient_clockwork_renegade_loopback_failed', '_transient_timeout_clockwork_renegade_loopback_failed'] as $option) {
            $this->assertTrue(Uninstaller::coversOption($option), $option);
        }
        $this->assertContains('clockwork_renegade_secret_pending', Uninstaller::optionNames());
        $this->assertContains('clockwork_renegade_install_state', Uninstaller::optionNames());
        foreach (['_clockwork_2fa_failures', '_clockwork_2fa_locked_until', '_clockwork_2fa_backup_codes_needed'] as $meta) {
            $this->assertTrue(Uninstaller::likeMatches(Uninstaller::USER_META_PATTERN, $meta), $meta);
        }

        update_option('clockwork_renegade_secret_pending', 'next');
        Uninstaller::run();
        $this->assertFalse(get_option('clockwork_renegade_secret_pending'));
    }
}
