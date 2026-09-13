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

    public function testPrivacyPolicyRegistersContent(): void
    {
        PrivacyPolicy::register();

        $this->assertArrayHasKey('Clockwork Renegade', $GLOBALS['wp_test_privacy_content']);
        $this->assertStringContainsString('Clockwork Control', $GLOBALS['wp_test_privacy_content']['Clockwork Renegade']);
        $this->assertStringContainsString('HMAC-authenticated', $GLOBALS['wp_test_privacy_content']['Clockwork Renegade']);
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
    }
}
