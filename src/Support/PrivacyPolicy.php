<?php

namespace ClockworkRenegade\Support;

class PrivacyPolicy
{
    public static function register(): void
    {
        if (! function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = sprintf(
            '<p class="privacy-policy-tutorial">%s</p>' .
            '<strong class="privacy-policy-tutorial">%s</strong>' .
            '<p>%s</p>',
            __('This website connects to Clockwork Control (operated by Clockwork Web Dev, LLC) for remote site health monitoring, automated backups, and routine maintenance.', 'clockwork-renegade'),
            __('What Data Is Collected and Transmitted:', 'clockwork-renegade'),
            __('When paired, Clockwork Renegade transmits environment telemetry (PHP, WordPress, and server software), plugin and theme inventories, cron health, disk metrics, administrator account names and emails, optional contact-form test results, malware-scan findings, and — when an operator starts a backup — a full database dump plus wp-content files to the AWS S3 bucket configured in Clockwork Control. Clockwork Control may also request a one-time SSO login URL for a named administrator. Nothing is sent until an administrator pastes the Connection Key into Clockwork Control. An optional Sucuri SiteCheck run (administrator-clicked) sends this site\'s public URL to sitecheck.sucuri.net.', 'clockwork-renegade')
        );

        wp_add_privacy_policy_content(
            __('Clockwork Renegade', 'clockwork-renegade'),
            wp_kses_post($content)
        );
    }
}
