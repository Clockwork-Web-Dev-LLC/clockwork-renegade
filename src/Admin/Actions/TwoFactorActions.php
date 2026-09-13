<?php

namespace ClockworkRenegade\Admin\Actions;

use ClockworkRenegade\Admin\Pages\TwoFactorPage;
use ClockworkRenegade\TwoFactor\UserSettings;
use ClockworkRenegade\TwoFactor\WflsMigrator;

/**
 * admin-post.php handler for the Login Security page.
 *
 * One hook, one `op` discriminator: begin / confirm / cancel / disable /
 * regenerate / migrate. Every op acts on the CURRENT USER ONLY — there is
 * deliberately no way to enroll, disable, or migrate someone else's
 * account from here.
 *
 * Auth model mirrors RunSecurityScanAction: WP nonce + manage_options.
 * No HMAC — that layer is for Clockwork machine-to-machine calls.
 *
 * Backup-code handoff: ops that mint codes (confirm, migrate, regenerate)
 * can't put them in the redirect URL — query strings land in access logs
 * and browser history. They go into a 60s single-read transient; only the
 * random token rides the redirect, and TwoFactorPage redeems it.
 */
class TwoFactorActions
{
    public const ACTION_HOOK = 'clockwork_two_factor';

    public const NONCE_ACTION = 'clockwork_two_factor';

    public function register(): void
    {
        add_action('admin_post_'.self::ACTION_HOOK, [$this, 'handle']);
    }

    public function handle(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage login security on this site.', 'clockwork-renegade'), 403);
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die(esc_html__('Invalid request — please return to the Login Security page and try again.', 'clockwork-renegade'), 400);
        }

        $userId = get_current_user_id();
        $op = isset($_POST['op']) ? (string) $_POST['op'] : '';

        switch ($op) {
            case 'begin':
                UserSettings::beginEnrollment($userId);
                $this->redirect();

            case 'cancel':
                delete_user_meta($userId, UserSettings::META_PENDING_SECRET);
                $this->redirect(['flash' => __('Setup cancelled — nothing was changed.', 'clockwork-renegade')]);

            case 'confirm':
                $codes = UserSettings::confirmEnrollment($userId, (string) ($_POST['code'] ?? ''));
                if ($codes === false) {
                    $this->redirect(['flash_error' => __('That code didn\'t match — check your authenticator app and try again.', 'clockwork-renegade')]);
                }
                $this->redirect([
                    'flash' => __('Two-factor authentication is on. Your next sign-in will ask for a code.', 'clockwork-renegade'),
                    'codes' => $this->stashCodes($userId, $codes),
                ]);

            case 'disable':
                UserSettings::disable($userId);
                $this->redirect(['flash' => __('Two-factor authentication is off for your account.', 'clockwork-renegade')]);

            case 'regenerate':
                if (! UserSettings::isEnabled($userId)) {
                    $this->redirect(['flash_error' => __('Two-factor isn\'t enabled — nothing to regenerate.', 'clockwork-renegade')]);
                }
                $codes = UserSettings::generateBackupCodes($userId);
                $this->redirect([
                    'flash' => __('Fresh backup codes generated — the old set no longer works.', 'clockwork-renegade'),
                    'codes' => $this->stashCodes($userId, $codes),
                ]);

            case 'migrate':
                $codes = WflsMigrator::migrate($userId);
                if ($codes === false) {
                    $this->redirect(['flash_error' => __('Nothing to migrate for your account.', 'clockwork-renegade')]);
                }
                $this->redirect([
                    'flash' => __('Migrated from Wordfence Login Security — the same authenticator app entry now protects your Clockwork sign-ins.', 'clockwork-renegade'),
                    'codes' => $this->stashCodes($userId, $codes),
                ]);

            case 'remove_wfls':
                $result = WflsMigrator::removeWfls();
                $this->redirect($result['ok']
                    ? ['flash' => $result['message']]
                    : ['flash_error' => $result['message']]);

            default:
                wp_die(esc_html__('Unknown operation.', 'clockwork-renegade'), 400);
        }
    }

    /**
     * @param  array<int, string>  $codes
     * @return string token to ride the redirect
     */
    private function stashCodes(int $userId, array $codes): string
    {
        $token = bin2hex(random_bytes(16));
        set_transient(TwoFactorPage::CODES_TRANSIENT_PREFIX.$token, [
            'user_id' => $userId,
            'codes' => $codes,
        ], 60);

        return $token;
    }

    /**
     * @param  array<string, string>  $args
     */
    private function redirect(array $args = []): void
    {
        wp_safe_redirect(add_query_arg(
            array_merge(['page' => TwoFactorPage::SLUG], array_map('rawurlencode', $args)),
            admin_url('admin.php')
        ));
        exit;
    }
}
