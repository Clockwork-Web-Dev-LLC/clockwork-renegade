<?php

namespace ClockworkRenegade\Tests;

use ClockworkRenegade\Admin\Pages\ConnectionPage;
use ClockworkRenegade\Auth\HmacVerifier;
use ClockworkRenegade\Auth\Secret;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_User;

class ConnectionPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_home_url'] = 'https://mysite.example.com';
        $GLOBALS['wp_test_current_user'] = null;
    }

    public function testConnectionKeyStructure(): void
    {
        $secret = Secret::ensure();
        $siteUrl = home_url();

        $connectionKey = base64_encode(wp_json_encode([
            'url' => $siteUrl,
            'secret' => $secret,
            'variant' => 'renegade',
        ]));

        $decoded = json_decode(base64_decode($connectionKey), true);
        $this->assertIsArray($decoded);
        $this->assertSame('https://mysite.example.com', $decoded['url']);
        $this->assertSame($secret, $decoded['secret']);
        $this->assertSame('renegade', $decoded['variant']);
        $this->assertSame(64, strlen($decoded['secret']));
    }

    public function testConnectionPageRendersCleanly(): void
    {
        ob_start();
        ConnectionPage::render();
        $output = ob_get_clean();

        $this->assertStringContainsString('Connection Key', $output);
        $this->assertStringContainsString('https://mysite.example.com', $output);
        $this->assertStringContainsString('Awaiting Connection', $output);
        $this->assertStringContainsString('cwkCopyConnectionKey', $output);
        $this->assertStringContainsString('Shared secret', $output);
    }

    public function testConnectionPageMasksCredentialsInDom(): void
    {
        $secret = Secret::ensure();
        ob_start();
        ConnectionPage::render();
        $output = ob_get_clean();

        // Plaintext secret must never appear directly in HTML or JS onclick attributes
        $this->assertStringNotContainsString($secret, $output);
        $this->assertStringContainsString('••••••••••••••••••••••••••••••••', $output);
    }

    public function testConnectionPageRestrictedForNonAgencyAdmin(): void
    {
        update_option('clockwork_renegade_branding', [
            'agency_email_domains' => '@clockworkwd.com',
        ]);
        $clientUser = new WP_User();
        $clientUser->user_email = 'admin@clientdomain.com';
        $GLOBALS['wp_test_current_user'] = $clientUser;

        ob_start();
        ConnectionPage::render();
        $output = ob_get_clean();

        $this->assertStringContainsString('Connection Credentials Restricted', $output);
        $this->assertStringNotContainsString('Copy Connection Key', $output);
        $this->assertStringNotContainsString('cwkCopyConnectionKey', $output);
    }

    public function testAjaxRevealCredentialsUnauthorizedForNonAgency(): void
    {
        update_option('clockwork_renegade_branding', [
            'agency_email_domains' => '@clockworkwd.com',
        ]);
        $clientUser = new WP_User();
        $clientUser->user_email = 'admin@clientdomain.com';
        $GLOBALS['wp_test_current_user'] = $clientUser;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_send_json_error [403]');

        ConnectionPage::ajaxRevealCredentials();
    }

    public function testAjaxRevealCredentialsSuccessForAgency(): void
    {
        update_option('clockwork_renegade_branding', [
            'agency_email_domains' => '@clockworkwd.com',
        ]);
        $agencyUser = new WP_User();
        $agencyUser->user_email = 'tech@clockworkwd.com';
        $GLOBALS['wp_test_current_user'] = $agencyUser;

        ob_start();
        ConnectionPage::ajaxRevealCredentials();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertTrue($data['success']);
        $this->assertNotEmpty($data['data']['connection_key']);
        $this->assertNotEmpty($data['data']['secret']);
        $this->assertSame(64, strlen($data['data']['secret']));
    }

    public function testHmacVerificationUpdatesLastContactOption(): void
    {
        $secret = Secret::ensure();
        $timestamp = time();
        $method = 'GET';
        $route = '/clockwork-renegade/v1/health';
        $body = '';

        $payload = "GET\n/wp-json/clockwork-renegade/v1/health\n{$timestamp}\n";
        $signature = hash_hmac('sha256', $payload, $secret);

        $request = new WP_REST_Request($method, $route, [], [
            'x-clockwork-timestamp' => (string) $timestamp,
            'x-clockwork-signature' => $signature,
        ], $body);

        $result = HmacVerifier::verify($request);
        $this->assertTrue($result);

        $lastContact = get_option('clockwork_renegade_last_contact_at');
        $this->assertNotEmpty($lastContact);
        $this->assertGreaterThanOrEqual($timestamp - 1, $lastContact);
    }
}
