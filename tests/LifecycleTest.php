<?php

namespace ClockworkRenegade\Tests;

use ClockworkRenegade\Plugin;
use ClockworkRenegade\Support\PrivacyPolicy;
use PHPUnit\Framework\TestCase;

class LifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_transients'] = [];
        $GLOBALS['wp_test_privacy_content'] = [];
        $GLOBALS['wpdb'] = new \wpdb();
    }

    public function testActivationRedirectTransient(): void
    {
        set_transient('clockwork_renegade_activation_redirect', true, 60);
        $this->assertTrue(get_transient('clockwork_renegade_activation_redirect'));

        delete_transient('clockwork_renegade_activation_redirect');
        $this->assertFalse(get_transient('clockwork_renegade_activation_redirect'));
    }

    public function testBootstrapRedirectsActivationToConnectionMenuSlug(): void
    {
        $bootstrap = (string) file_get_contents(dirname(__DIR__).'/clockwork-renegade.php');
        $this->assertStringContainsString("admin_url('admin.php?page=clockwork-connection')", $bootstrap);
        $this->assertStringNotContainsString('clockwork-renegade-connection', $bootstrap);
        $this->assertSame('clockwork-connection', \ClockworkRenegade\Admin\Pages\ConnectionPage::SLUG);
    }

    /**
     * wp_add_privacy_policy_content() before admin_init is rejected by core
     * with _doing_it_wrong, so registering must only hook admin_init.
     */
    public function testPrivacyPolicyContentIsAddedOnAdminInit(): void
    {
        $GLOBALS['wp_test_actions']['admin_init'] = [];

        PrivacyPolicy::register();

        $this->assertSame([], $GLOBALS['wp_test_privacy_content'], 'Nothing may be added before admin_init');
        $this->assertSame([[PrivacyPolicy::class, 'addContent']], $GLOBALS['wp_test_actions']['admin_init']);

        PrivacyPolicy::addContent();

        $this->assertArrayHasKey('Clockwork Renegade', $GLOBALS['wp_test_privacy_content']);
        $this->assertStringContainsString('Clockwork Control', $GLOBALS['wp_test_privacy_content']['Clockwork Renegade']);
        $this->assertStringContainsString('Connection Key', $GLOBALS['wp_test_privacy_content']['Clockwork Renegade']);
        $this->assertStringContainsString('AWS S3', $GLOBALS['wp_test_privacy_content']['Clockwork Renegade']);
    }

    public function testUninstallRemovesOptionsAndDropsTables(): void
    {
        update_option('clockwork_renegade_secret', 'secret123');
        update_option('clockwork_renegade_branding', ['brand_name' => 'Agency']);
        update_option('clockwork_renegade_maintenance_mode', ['enabled' => true]);

        if (! defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }

        require dirname(__DIR__) . '/uninstall.php';

        $this->assertFalse(get_option('clockwork_renegade_secret'));
        $this->assertFalse(get_option('clockwork_renegade_branding'));
        $this->assertFalse(get_option('clockwork_renegade_maintenance_mode'));

        $queries = implode("\n", $GLOBALS['wpdb']->queries);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `wp_clockwork_renegade_action_log`', $queries);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `wp_clockwork_renegade_auth_failures`', $queries);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `wp_clockwork_renegade_resource_samples`', $queries);
    }

    public function testCodeSnippetsRouteIsExcluded(): void
    {
        $this->assertFalse(
            class_exists('ClockworkRenegade\Rest\CodeSnippetRoute'),
            'CodeSnippetRoute must be completely excluded from Clockwork Renegade for WordPress.org compliance.'
        );

        $this->assertNotContains(
            'code-snippets',
            Plugin::CAPABILITIES,
            'code-snippets capability must not be advertised in Clockwork Renegade.'
        );

        $this->assertFileDoesNotExist(
            dirname(__DIR__).'/src/Admin/Pages/UnlockPage.php',
            'UnlockPage must not ship in the public WordPress.org plugin.'
        );
    }
}
