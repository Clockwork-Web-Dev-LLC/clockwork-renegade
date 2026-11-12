<?php

namespace ClockworkRenegade\Tests;

use ClockworkRenegade\Admin\Support\SupportForm;
use PHPUnit\Framework\TestCase;

class SupportFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_filters'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wp_test_filters'] = [];
        parent::tearDown();
    }

    /** @return array{first_name: string, last_name: string, email: string, website_url: string, short_desc: string, long_desc: string, approved: bool, subscribe: bool} */
    private static function ticket(): array
    {
        return [
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'website_url' => 'https://example.com', 'short_desc' => 'Help', 'long_desc' => 'Details',
            'approved' => true, 'subscribe' => true,
        ];
    }

    /**
     * The public plugin used to ship one vendor's form id, field map and
     * hourly rate. Out of the box there is now no form, no terms and no opt-in.
     */
    public function testDefaultsAreNeutral(): void
    {
        $config = SupportForm::config();

        $this->assertSame(0, $config['form_id']);
        $this->assertSame('', $config['billing_notice']);
        $this->assertSame('', $config['subscribe_text']);
        $this->assertSame([], array_filter($config['fields']));
        $this->assertSame([], $config['static_fields']);

        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Admin/Support/SupportForm.php');
        $this->assertStringNotContainsString('hourly rate', $source);
        $this->assertDoesNotMatchRegularExpression('/input_\d+_\d+\s*=/', $source);
    }

    public function testSubmissionFollowsTheConfiguredMap(): void
    {
        add_filter('clockwork_renegade_support_form', fn (array $c) => array_merge($c, [
            'form_id' => 7,
            'fields' => ['first_name' => 'input_1_3', 'email' => 'input_3', 'long_desc' => 'input_4', 'approved' => 'input_6_1', 'subscribe' => 'input_9_1'],
            'static_fields' => ['input_7' => 'Existing client'],
            'billing_notice' => 'I accept the billing terms.',
        ]));

        $config = SupportForm::config();
        $body = SupportForm::buildSubmission(self::ticket(), $config);

        $this->assertSame(7, $config['form_id']);
        $this->assertSame('Jane', $body['input_1_3']);
        $this->assertSame('jane@example.com', $body['input_3']);
        $this->assertSame('Details', $body['input_4']);
        $this->assertSame('I accept the billing terms.', $body['input_6_1']);
        $this->assertArrayNotHasKey('input_9_1', $body, 'No opt-in text configured, so nothing is sent for it');
        $this->assertSame('Existing client', $body['input_7']);
        $this->assertArrayNotHasKey('last_name', $body);
    }

    public function testModalShowsBillingTermsOnlyWhenConfigured(): void
    {
        ob_start();
        SupportForm::renderModal();
        $plain = (string) ob_get_clean();

        add_filter('clockwork_renegade_support_form', fn (array $c) => array_merge($c, ['billing_notice' => 'Minimum charge applies.']));
        ob_start();
        SupportForm::renderModal();
        $withTerms = (string) ob_get_clean();

        $this->assertStringNotContainsString('name="approved"', $plain);
        $this->assertStringNotContainsString('name="subscribe"', $plain);
        $this->assertStringContainsString('name="approved"', $withTerms);
        $this->assertStringContainsString('Minimum charge applies.', $withTerms);
    }
}
