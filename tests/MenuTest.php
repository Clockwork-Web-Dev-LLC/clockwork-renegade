<?php

namespace ClockworkRenegade\Tests;

use ClockworkRenegade\Admin\Menu;
use ClockworkRenegade\WhiteLabel\WhiteLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_User;

class MenuTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_current_user'] = null;
        $GLOBALS['wp_test_filters'] = [];
    }

    /**
     * The out-of-the-box case: nothing configured, so nothing is hidden. A
     * fresh install must not hide its own menu from the administrator who
     * just installed it.
     */
    public function testNoConfiguredDomainsMeansEveryAdministratorSeesTheMenu(): void
    {
        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('owner@shop.example')));
        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('admin@client.example')));
    }

    public function testConfiguredDomainsGateTheMenu(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'agency_email_domains' => '@agency.example',
        ]);

        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('ops@agency.example')));
        $this->assertFalse(Menu::currentUserIsAgency(new WP_User('owner@shop.example')));
    }

    /**
     * Gating is an access-control decision, so it must apply whether or not
     * the cosmetic white-label flag happens to be switched on.
     */
    public function testGatingAppliesEvenWhenWhiteLabelIsDisabled(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'enabled' => false,
            'agency_email_domains' => 'agency.example',
        ]);

        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('ops@agency.example')));
        $this->assertFalse(Menu::currentUserIsAgency(new WP_User('owner@shop.example')));
    }

    /**
     * An operator who set a support address should never be able to lock
     * themselves out by forgetting to also list that domain.
     */
    public function testSupportEmailDomainIsAlwaysTreatedAsAgency(): void
    {
        update_option(WhiteLabel::OPTION_KEY, [
            'enabled' => true,
            'agency_email_domains' => '@agency.example',
            'support_email' => 'tech@superagency.example',
        ]);

        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('ops@agency.example')));
        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('sarah@superagency.example')));
        $this->assertFalse(Menu::currentUserIsAgency(new WP_User('owner@shop.example')));
    }

    #[DataProvider('domainFormatProvider')]
    public function testDomainListAcceptsCommonFormats(string $configured): void
    {
        update_option(WhiteLabel::OPTION_KEY, ['agency_email_domains' => $configured]);

        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('ops@agency.example')));
        $this->assertFalse(Menu::currentUserIsAgency(new WP_User('owner@shop.example')));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function domainFormatProvider(): array
    {
        return [
            'bare domain' => ['agency.example'],
            'leading at' => ['@agency.example'],
            'full address' => ['someone@agency.example'],
            'uppercase' => ['@Agency.EXAMPLE'],
            'comma separated' => ['@other.test, @agency.example'],
            'newline separated' => ["@other.test\n@agency.example"],
            'padded with spaces' => ['  @agency.example  '],
        ];
    }

    public function testMatchIsCaseInsensitiveOnTheUserAddress(): void
    {
        update_option(WhiteLabel::OPTION_KEY, ['agency_email_domains' => '@agency.example']);

        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('OPS@Agency.Example')));
    }

    /**
     * A configured domain must not match a lookalike registrable domain —
     * "notagency.example" ends with "agency.example" as a bare substring,
     * so the "@" in the stored form is what keeps them apart.
     */
    public function testLookalikeDomainDoesNotMatch(): void
    {
        update_option(WhiteLabel::OPTION_KEY, ['agency_email_domains' => '@agency.example']);

        $this->assertFalse(Menu::currentUserIsAgency(new WP_User('ops@notagency.example')));
    }

    public function testFilterCanOverrideConfiguredDomains(): void
    {
        update_option(WhiteLabel::OPTION_KEY, ['agency_email_domains' => '@agency.example']);

        add_filter('clockwork_renegade_agency_email_domains', fn () => ['@vanguard.example']);

        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('help@vanguard.example')));
        $this->assertFalse(Menu::currentUserIsAgency(new WP_User('ops@agency.example')));
    }

    public function testUnparseableEntriesAreIgnoredRatherThanGatingOnNothing(): void
    {
        update_option(WhiteLabel::OPTION_KEY, ['agency_email_domains' => '@, ,,  ']);

        // Nothing parseable survived, so this is the "unconfigured" case.
        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('owner@shop.example')));
    }

    public function testUserWithoutAnEmailIsNotAgencyWhenGatingIsOn(): void
    {
        update_option(WhiteLabel::OPTION_KEY, ['agency_email_domains' => '@agency.example']);

        $this->assertFalse(Menu::currentUserIsAgency(new WP_User('')));
    }

    /**
     * No vendor domain is treated as agency behind the configuration's back:
     * a white-labelled install grants agency access only to what it lists.
     */
    public function testOnlyConfiguredDomainsAreAgency(): void
    {
        update_option(WhiteLabel::OPTION_KEY, ['agency_email_domains' => '@agency.example']);
        add_filter('clockwork_renegade_agency_email_domains', fn () => ['@vanguard.example']);

        $this->assertTrue(Menu::currentUserIsAgency(new WP_User('help@vanguard.example')));
        $this->assertFalse(Menu::currentUserIsAgency(new WP_User('legacy@vendor.example')));
        $this->assertFalse(Menu::currentUserIsAgency(new WP_User('ops@agency.example')));
    }
}
