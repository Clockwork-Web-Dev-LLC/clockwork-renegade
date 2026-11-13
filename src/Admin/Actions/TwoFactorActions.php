<?php

namespace ClockworkRenegade\Admin\Actions;

use ClockworkRenegade\ActionLog\Repository as ActionLogRepository;
use ClockworkRenegade\Admin\Pages\TwoFactorPage;
use ClockworkRenegade\TwoFactor\LoginInterceptor;
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
 * Changing an active second factor — disable, regenerate backup codes, or
 * confirming a new secret over an active one — also needs a current code
 * from the authenticator app (or a backup code) in `current_code`. A nonce
 * and a session alone would let a hijacked session silently remove or swap
 * the factor that is meant to survive a stolen password. Bad codes count
 * towards the same per-user lockout as the login challenge. Every such
 * change, and every refused attempt, is written to the action log.
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

    /** Ops that change an ACTIVE second factor and so need a current code. */
    public const OPS_NEEDING_CURRENT_CODE = ['disable', 'regenerate', 'confirm'];

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
        $wasEnabled = UserSettings::isEnabled($userId);

        if (in_array($op, self::OPS_NEEDING_CURRENT_CODE, true)) {
            $refusal = self::refusalForActiveFactorChange($userId, $op, (string) ($_POST['current_code'] ?? ''));
            if ($refusal !== null) {
                $this->redirect(['flash_error' => $refusal]);
            }
        }

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
                self::log($userId, $wasEnabled ? '2fa_reenrolled' : '2fa_enabled', $wasEnabled
                    ? 'Replaced the two-factor secret with a new authenticator app entry.'
                    : 'Turned on two-factor authentication.');
                $this->redirect([
                    'flash' => __('Two-factor authentication is on. Your next sign-in will ask for a code.', 'clockwork-renegade'),
                    'codes' => $this->stashCodes($userId, $codes),
                ]);

            case 'disable':
                UserSettings::disable($userId);
                self::log($userId, '2fa_self_disabled', 'Turned off two-factor authentication for their own account.');
                $this->redirect(['flash' => __('Two-factor authentication is off for your account.', 'clockwork-renegade')]);

            case 'regenerate':
                if (! UserSettings::isEnabled($userId)) {
                    $this->redirect(['flash_error' => __('Two-factor isn\'t enabled — nothing to regenerate.', 'clockwork-renegade')]);
                }
                $codes = UserSettings::generateBackupCodes($userId);
                self::log($userId, '2fa_backup_codes_regenerated', 'Generated a fresh set of backup codes; the previous set no longer works.');
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
     * Null when $op may proceed, otherwise the message to show. Only an
     * active factor is protected: with 2FA off there is nothing to prove.
     */
    public static function refusalForActiveFactorChange(int $userId, string $op, string $currentCode): ?string
    {
        if (! UserSettings::isEnabled($userId)) {
            return null;
        }

        $outcome = LoginInterceptor::verifyCode($userId, $currentCode);
        if ($outcome === 'ok') {
            return null;
        }

        self::log($userId, '2fa_change_refused', sprintf('Refused "%s": the current two-factor code was missing or wrong.', $op), false);

        return $outcome === 'locked'
            ? __('Too many incorrect codes — two-factor changes are paused for a few minutes.', 'clockwork-renegade')
            : __('Enter a current code from your authenticator app (or a backup code) to make this change.', 'clockwork-renegade');
    }

    private static function log(int $userId, string $actionType, string $summary, bool $ok = true): void
    {
        $user = get_userdata($userId);
        try {
            ActionLogRepository::insert([
                'action_type' => $actionType,
                'target' => $user ? (string) $user->user_login : (string) $userId,
                'summary' => $summary,
                'ok' => $ok,
                'actor' => 'manual',
            ]);
        } catch (\Throwable $e) {
            unset($e);
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
        wp_safe_redirect(self::redirectUrl($args));
        exit;
    }

    /**
     * Login Security page URL carrying $args. add_query_arg() does not
     * encode values, so a display name containing & or # in a flash message
     * would otherwise cut the message short or turn into extra parameters.
     *
     * @param  array<string, string>  $args
     */
    public static function redirectUrl(array $args = []): string
    {
        return add_query_arg(
            array_merge(['page' => TwoFactorPage::SLUG], array_map('rawurlencode', $args)),
            admin_url('admin.php')
        );
    }
}
