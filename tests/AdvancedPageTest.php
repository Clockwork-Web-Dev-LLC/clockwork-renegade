<?php

namespace ClockworkRenegade\Tests;

use ClockworkRenegade\Admin\Layout;
use ClockworkRenegade\Admin\Pages\AdvancedPage;
use ClockworkRenegade\Gatekeeper\Settings as GatekeeperSettings;
use ClockworkRenegade\Gatekeeper\Store as GatekeeperStore;
use PHPUnit\Framework\TestCase;
use WP_User;

class AdvancedPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_current_user'] = (object) ['ID' => 1, 'user_email' => 'admin@example.com'];
        $GLOBALS['wp_test_object_cache'] = [];
        $GLOBALS['wp_test_object_cache_expire'] = [];
        if (isset($GLOBALS['wpdb']->table_rows)) {
            $GLOBALS['wpdb']->table_rows['wp_clockwork_lockouts'] = [];
        }
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > 1) {
            ob_end_clean();
        }
        parent::tearDown();
    }

    public function testSubtabsDefinition(): void
    {
        $subtabs = AdvancedPage::subtabs();
        $slugs = array_column($subtabs, 'slug');

        $this->assertContains('gatekeeper', $slugs);
        $this->assertContains('connection', $slugs);
        $this->assertContains('updates', $slugs);
        $this->assertContains('notifications', $slugs);
        $this->assertContains('caching', $slugs);
        $this->assertContains('diagnostics', $slugs);
    }

    public function testLayoutTabsIncludesAdvancedAndExcludesNotifications(): void
    {
        $tabs = Layout::tabs();
        $slugs = array_column($tabs, 'slug');

        $this->assertContains('advanced', $slugs);
        $this->assertNotContains('notifications', $slugs);
        $this->assertNotContains('connection', $slugs);
        $this->assertNotContains('update-coverage', $slugs);
    }

    public function testRenderGatekeeperSubtab(): void
    {
        GatekeeperSettings::update(['enabled' => true, 'threshold' => 3]);
        GatekeeperStore::lock('203.0.113.88');

        ob_start();
        AdvancedPage::render('gatekeeper');
        $output = ob_get_clean();

        $this->assertStringContainsString('Gatekeeper', $output);
        $this->assertStringContainsString('Active Lockouts', $output);
        $this->assertStringContainsString('203.0.113.88', $output);
        $this->assertStringContainsString('Protection Settings', $output);
        $this->assertStringContainsString('Failed Attempt Threshold', $output);
        $this->assertStringContainsString('Unlock All IPs', $output);
    }

    public function testRenderConnectionSubtab(): void
    {
        ob_start();
        AdvancedPage::render('connection');
        $output = ob_get_clean();

        $this->assertStringContainsString('Connection Key', $output);
        $this->assertStringContainsString('cwkCopyConnectionKey', $output);
    }

    public function testRenderUpdatesSubtab(): void
    {
        ob_start();
        AdvancedPage::render('updates');
        $output = ob_get_clean();

        $this->assertStringContainsString('Update coverage', $output);
    }

    public function testRenderNotificationsSubtab(): void
    {
        ob_start();
        AdvancedPage::render('notifications');
        $output = ob_get_clean();

        $this->assertStringContainsString('Notifications', $output);
    }

    public function testRenderCachingSubtab(): void
    {
        ob_start();
        AdvancedPage::render('caching');
        $output = ob_get_clean();

        $this->assertStringContainsString('Object Cache Engine', $output);
        $this->assertStringContainsString('Compatibility Shields', $output);
        $this->assertStringContainsString('Perfmatters Compatibility', $output);
    }

    public function testRenderDiagnosticsSubtab(): void
    {
        ob_start();
        AdvancedPage::render('diagnostics');
        $output = ob_get_clean();

        $this->assertStringContainsString('System Environment', $output);
        $this->assertStringContainsString('PHP Version', $output);
        $this->assertStringContainsString('WordPress Version', $output);
        $this->assertStringContainsString('Gatekeeper Engine', $output);
        $this->assertStringContainsString('Copy System Report', $output);
    }

    public function testHandleSaveGatekeeperUpdatesSettingsForAgency(): void
    {
        $_POST = [
            'enabled' => '1',
            'threshold' => '6',
            'window_minutes' => '15',
            'lockout_minutes' => '30',
            'consecutive_lockouts_for_extended' => '5',
            'extended_lockout_hours' => '48',
            'headline' => 'Custom Lockout Headline',
            'body' => 'Custom body message for locked users.',
            'support_email' => 'help@example.com',
            'support_url' => 'https://example.com/help',
            'ignore_ips' => "198.51.100.1\n198.51.100.2",
            'ignore_cidrs' => "198.51.100.0/24",
        ];

        AdvancedPage::handleSaveGatekeeper();

        $saved = GatekeeperSettings::get();
        $this->assertTrue($saved['enabled']);
        $this->assertSame(6, $saved['threshold']);
        $this->assertSame(900, $saved['window_seconds']);
        $this->assertSame(1800, $saved['lockout_seconds']);
        $this->assertSame(5, $saved['consecutive_lockouts_for_extended']);
        $this->assertSame(172800, $saved['extended_lockout_seconds']);
        $this->assertSame('Custom Lockout Headline', $saved['headline']);
        $this->assertSame('Custom body message for locked users.', $saved['body']);
        $this->assertSame('help@example.com', $saved['support_email']);
        $this->assertSame(['198.51.100.1', '198.51.100.2'], $saved['ignore_ips']);
        $this->assertSame(['198.51.100.0/24'], $saved['ignore_cidrs']);
    }

    public function testHandleSaveGatekeeperForbiddenForNonAgency(): void
    {
        update_option('clockwork_renegade_branding', [
            'agency_email_domains' => '@clockworkwd.com',
        ]);
        $clientUser = new WP_User();
        $clientUser->user_email = 'admin@clientdomain.com';
        $GLOBALS['wp_test_current_user'] = $clientUser;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_die [403]');

        AdvancedPage::handleSaveGatekeeper();
    }

    public function testHandleUnlockAllForbiddenForNonAgency(): void
    {
        update_option('clockwork_renegade_branding', [
            'agency_email_domains' => '@clockworkwd.com',
        ]);
        $clientUser = new WP_User();
        $clientUser->user_email = 'admin@clientdomain.com';
        $GLOBALS['wp_test_current_user'] = $clientUser;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_die [403]');

        AdvancedPage::handleUnlockAll();
    }

    public function testHandleFlushCacheForbiddenForNonAgency(): void
    {
        update_option('clockwork_renegade_branding', [
            'agency_email_domains' => '@clockworkwd.com',
        ]);
        $clientUser = new WP_User();
        $clientUser->user_email = 'admin@clientdomain.com';
        $GLOBALS['wp_test_current_user'] = $clientUser;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_die [403]');

        AdvancedPage::handleFlushCache();
    }

    public function testHandleUnlockIpAllowedForNonAgency(): void
    {
        update_option('clockwork_renegade_branding', [
            'agency_email_domains' => '@clockworkwd.com',
        ]);
        $clientUser = new WP_User();
        $clientUser->user_email = 'admin@clientdomain.com';
        $GLOBALS['wp_test_current_user'] = $clientUser;

        GatekeeperSettings::update(['enabled' => true]);
        $ip = '203.0.113.44';
        GatekeeperStore::lock($ip);
        $this->assertNotNull(GatekeeperStore::isLocked($ip));

        $_POST = ['ip' => $ip];

        AdvancedPage::handleUnlockIp();

        $this->assertNull(GatekeeperStore::isLocked($ip));
    }

    public function testHandleUnlockIpClearsIpLockout(): void
    {
        GatekeeperSettings::update(['enabled' => true]);
        $ip = '203.0.113.44';
        GatekeeperStore::lock($ip);
        $this->assertNotNull(GatekeeperStore::isLocked($ip));

        $_POST = ['ip' => $ip];

        AdvancedPage::handleUnlockIp();

        $this->assertNull(GatekeeperStore::isLocked($ip));
    }

    public function testHandleUnlockAllClearsAllLockouts(): void
    {
        GatekeeperSettings::update(['enabled' => true]);
        GatekeeperStore::lock('203.0.113.11');
        GatekeeperStore::lock('203.0.113.22');
        $this->assertCount(2, GatekeeperStore::activeLockouts());

        AdvancedPage::handleUnlockAll();

        $this->assertCount(0, GatekeeperStore::activeLockouts());
    }

    public function testHandleFlushCacheInvokesWpCacheFlush(): void
    {
        AdvancedPage::handleFlushCache();
        $this->assertStringContainsString('flushed=1', $GLOBALS['wp_test_last_redirect'] ?? '');
    }
}
