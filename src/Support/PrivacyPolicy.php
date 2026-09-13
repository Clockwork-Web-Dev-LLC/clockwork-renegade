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
            __('When paired with a Clockwork Control dashboard, Clockwork Renegade transmits server environment telemetry, active WordPress core, theme, and plugin inventory versions, scheduled cron health, and diagnostic performance metrics via HMAC-authenticated HTTPS requests. No visitor personal data or comment author PII is collected or sent to Clockwork Control without explicit administrator configuration.', 'clockwork-renegade')
        );

        wp_add_privacy_policy_content(
            __('Clockwork Renegade', 'clockwork-renegade'),
            wp_kses_post($content)
        );
    }
}
