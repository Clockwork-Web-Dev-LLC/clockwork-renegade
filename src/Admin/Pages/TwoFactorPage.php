<?php

namespace ClockworkRenegade\Admin\Pages;

use ClockworkRenegade\Admin\Layout;
use ClockworkRenegade\TwoFactor\EnrollmentNudge;
use ClockworkRenegade\TwoFactor\Totp;
use ClockworkRenegade\TwoFactor\UserSettings;
use ClockworkRenegade\TwoFactor\WflsMigrator;

/**
 * Clockwork → Login Security admin page.
 *
 * Two cards:
 *   1. "Your two-factor authentication" — the CURRENT USER's enrollment.
 *      Renders one of four states: WFLS-migratable (banner), enrolled
 *      (status + manage buttons), mid-enrollment (QR + confirm form), or
 *      not enrolled (set-up button).
 *   2. "Team status" — read-only enrollment roll-call for admins/editors,
 *      including who is still on Wordfence Login Security. Exists so the
 *      operator can see at a glance who to nudge before WFLS goes away.
 *
 * Backup codes are displayed exactly once. Action handlers can't put them
 * in the redirect URL (query strings leak into server logs and browser
 * history), so TwoFactorActions stashes them in a 60-second transient
 * keyed by a random token and passes only the token; this page redeems
 * and deletes it on first render.
 *
 * QR rendering happens client-side (bundled qrcode.js, no external
 * requests) from the otpauth:// URI — the secret never travels anywhere
 * except this authenticated page load.
 */
class TwoFactorPage
{
    public const SLUG = 'clockwork-two-factor';

    public const CODES_TRANSIENT_PREFIX = 'clockwork_2fa_codes_';

    public static function render(): void
    {
        Layout::render('two-factor', [self::class, 'renderBody']);
    }

    public static function renderBody(): void
    {
        Layout::pageHeader(
            __('Login Security', 'clockwork-renegade'),
            __('Two-factor authentication for this site\'s sign-ins — codes from your authenticator app, with backup codes for recovery.', 'clockwork-renegade')
        );

        if (! empty($_GET['flash'])) {
            printf(
                '<div class="notice notice-success" style="margin:0 0 16px;"><p>%s</p></div>',
                esc_html(rawurldecode((string) $_GET['flash']))
            );
        }
        if (! empty($_GET['flash_error'])) {
            printf(
                '<div class="notice notice-error" style="margin:0 0 16px;"><p>%s</p></div>',
                esc_html(rawurldecode((string) $_GET['flash_error']))
            );
        }

        self::renderFreshBackupCodes();
        self::renderSelfCard();
        self::renderWflsCleanupCard();
        self::renderTeamCard();
    }

    /**
     * One-time backup code reveal. The token arrives via redirect from
     * TwoFactorActions; the transient dies on first read (or after 60s).
     */
    private static function renderFreshBackupCodes(): void
    {
        $token = isset($_GET['codes']) ? (string) $_GET['codes'] : '';
        if (! preg_match('/^[a-f0-9]{32}$/', $token)) {
            return;
        }

        $key = self::CODES_TRANSIENT_PREFIX.$token;
        $stash = get_transient($key);
        delete_transient($key);

        if (! is_array($stash) || (int) ($stash['user_id'] ?? 0) !== get_current_user_id()) {
            return;
        }
        ?>
        <div class="clockwork-card" style="border-left: 4px solid #d63638; margin-bottom: 16px;">
            <div class="clockwork-card__body" style="background:#fef2f2;">
                <h2 style="margin-top:0;"><?php esc_html_e('Your backup codes — save these now', 'clockwork-renegade'); ?></h2>
                <p><?php echo wp_kses(
                    __('Each code works once, and this is the <strong>only time they will be shown</strong>. Store them somewhere safe (password manager, printed copy). If you lose your phone, a backup code is how you get back in.', 'clockwork-renegade'),
                    ['strong' => []]
                ); ?></p>
                <div style="display:grid;grid-template-columns:repeat(4,max-content);gap:8px 32px;font-family:monospace;font-size:15px;padding:12px 0 0;">
                    <?php foreach ((array) $stash['codes'] as $code) : ?>
                        <span><?php echo esc_html((string) $code); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    private static function renderSelfCard(): void
    {
        $userId = get_current_user_id();
        $user = wp_get_current_user();
        ?>
        <div class="clockwork-card" style="margin-bottom: 16px;">
            <div class="clockwork-card__body">
            <h2 style="margin-top:0;"><?php esc_html_e('Your two-factor authentication', 'clockwork-renegade'); ?></h2>
            <?php
            if (UserSettings::isEnabled($userId)) {
                self::renderEnrolledState($userId);
            } elseif (WflsMigrator::hasMigratable($userId)) {
                self::renderMigrationBanner($userId);
            } elseif (UserSettings::pendingSecret($userId) !== '') {
                self::renderConfirmState($userId, $user->user_login);
            } else {
                self::renderNotEnrolledState();
            }
            ?>
            </div>
        </div>
        <?php
    }

    private static function renderEnrolledState(int $userId): void
    {
        $remaining = UserSettings::backupCodesRemaining($userId);
        ?>
        <p>
            <span style="display:inline-block;padding:2px 10px;border-radius:10px;background:#edfaef;color:#00753d;font-weight:600;font-size:12px;"><?php esc_html_e('Enabled', 'clockwork-renegade'); ?></span>
            &nbsp;<?php esc_html_e('Sign-ins to your account require a code from your authenticator app.', 'clockwork-renegade'); ?>
        </p>
        <p style="color:#50575e;"><?php esc_html_e('Backup codes remaining:', 'clockwork-renegade'); ?> <strong><?php echo (int) $remaining; ?></strong>
            <?php if ($remaining <= 2) : ?>
                <span style="color:#d63638;"><?php esc_html_e('— running low, regenerate a fresh set.', 'clockwork-renegade'); ?></span>
            <?php endif; ?>
        </p>
        <div style="display:flex;gap:8px;">
            <?php self::actionForm('regenerate', __('Regenerate backup codes', 'clockwork-renegade'), 'button'); ?>
            <?php self::actionForm('disable', __('Disable two-factor', 'clockwork-renegade'), 'button', __('Disable two-factor authentication for your account? Your next sign-in will only need your password.', 'clockwork-renegade')); ?>
        </div>
        <?php
    }

    private static function renderMigrationBanner(int $userId): void
    {
        $wflsActive = WflsMigrator::isWflsActive();
        ?>
        <style>
            @keyframes cwk-spin { to { transform: rotate(360deg); } }
            .cwk-btn-loading { cursor: default !important; }
            .cwk-btn-loading::before {
                content: "";
                display: inline-block;
                width: 11px;
                height: 11px;
                border: 2px solid rgba(255,255,255,.35);
                border-top-color: #fff;
                border-radius: 50%;
                animation: cwk-spin .75s linear infinite;
                margin-right: 7px;
                vertical-align: middle;
            }
        </style>
        <?php if ($wflsActive) : ?>
            <div style="border-left:4px solid #dba617;background:#fcf9e8;padding:12px 16px;margin-bottom:12px;">
                <p style="margin:0 0 6px;"><?php echo wp_kses(
                    __('<strong>Wordfence Login Security is protecting this account today</strong> — but Wordfence is discontinuing that plugin.', 'clockwork-renegade'),
                    ['strong' => []]
                ); ?></p>
                <p style="margin:0;"><?php esc_html_e('Migrate your setup to Clockwork now: the entry already in your authenticator app keeps working, nothing to re-scan. You\'ll get a fresh set of backup codes. Wordfence Login Security stays active until every account has migrated — until then both prompts may appear at sign-in.', 'clockwork-renegade'); ?></p>
            </div>
        <?php else : ?>
            <div style="border-left:4px solid #d63638;background:#fcf0f1;padding:12px 16px;margin-bottom:12px;">
                <p style="margin:0 0 6px;"><?php echo wp_kses(
                    __('<strong>Wordfence Login Security has been removed, and with it your two-factor prompt.</strong>', 'clockwork-renegade'),
                    ['strong' => []]
                ); ?></p>
                <p style="margin:0;"><?php esc_html_e('Your setup is still in the database — migrate it now and the entry already in your authenticator app starts protecting your sign-ins again immediately.', 'clockwork-renegade'); ?></p>
            </div>
        <?php endif; ?>
        <?php self::actionForm('migrate', __('Migrate my two-factor setup', 'clockwork-renegade'), 'button button-primary', '', __('Migrating…', 'clockwork-renegade')); ?>
        <p style="color:#787c82;font-size:12px;margin-top:10px;">
            <?php esc_html_e('Prefer a clean start?', 'clockwork-renegade'); ?> <?php self::actionLink('begin', __('Set up from scratch instead', 'clockwork-renegade')); ?> <?php esc_html_e('— you\'ll scan a new QR code.', 'clockwork-renegade'); ?>
        </p>
        <?php
    }

    private static function renderConfirmState(int $userId, string $login): void
    {
        $secret = UserSettings::pendingSecret($userId);
        $issuer = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'WordPress';
        $uri = Totp::provisioningUri($secret, $login, (string) $issuer);
        ?>
        <p><?php echo wp_kses(
            __('<strong>Step 1.</strong> Scan this QR code with your authenticator app (Google Authenticator, Authy, 1Password…).', 'clockwork-renegade'),
            ['strong' => []]
        ); ?></p>
        <div id="cwk-2fa-qr" style="margin:8px 0;"></div>
        <p style="color:#787c82;font-size:12px;"><?php esc_html_e('Can\'t scan? Enter this key manually:', 'clockwork-renegade'); ?>
            <code style="user-select:all;"><?php echo esc_html(trim(chunk_split($secret, 4, ' '))); ?></code>
        </p>
        <p><?php echo wp_kses(
            __('<strong>Step 2.</strong> Enter the 6-digit code your app shows, to prove the pairing worked. Two-factor only switches on after this step.', 'clockwork-renegade'),
            ['strong' => []]
        ); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;align-items:center;">
            <input type="hidden" name="action" value="<?php echo esc_attr(\ClockworkRenegade\Admin\Actions\TwoFactorActions::ACTION_HOOK); ?>">
            <input type="hidden" name="op" value="confirm">
            <?php wp_nonce_field(\ClockworkRenegade\Admin\Actions\TwoFactorActions::NONCE_ACTION); ?>
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9\s]*"
                   style="width:120px;font-size:18px;letter-spacing:3px;text-align:center;" required autofocus>
            <button type="submit" class="button button-primary"><?php echo wp_kses(__('Verify &amp; enable', 'clockwork-renegade'), []); ?></button>
        </form>
        <p style="color:#787c82;font-size:12px;margin-top:10px;">
            <?php esc_html_e('Changed your mind?', 'clockwork-renegade'); ?> <?php self::actionLink('cancel', __('Cancel setup', 'clockwork-renegade')); ?>
        </p>
        <script>
            (function () {
                var qr = qrcode(0, 'M');
                qr.addData(<?php echo wp_json_encode($uri); ?>);
                qr.make();
                document.getElementById('cwk-2fa-qr').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2 });
            })();
        </script>
        <?php
    }

    private static function renderNotEnrolledState(): void
    {
        ?>
        <p>
            <span style="display:inline-block;padding:2px 10px;border-radius:10px;background:#f0f0f1;color:#50575e;font-weight:600;font-size:12px;"><?php esc_html_e('Not set up', 'clockwork-renegade'); ?></span>
            &nbsp;<?php esc_html_e('Your account signs in with a password only.', 'clockwork-renegade'); ?>
        </p>
        <p style="color:#50575e;"><?php esc_html_e('Two-factor authentication adds a 6-digit code from your phone to every sign-in, so a stolen password alone can\'t get in.', 'clockwork-renegade'); ?></p>
        <?php self::actionForm('begin', __('Set up two-factor authentication', 'clockwork-renegade'), 'button button-primary'); ?>
        <?php
    }

    /**
     * Shown only when the WFLS plugin is still installed but no existing
     * user's 2FA depends on it anymore — every setup either migrated or
     * was never there. That's the moment removing it is pure win: it's
     * deprecated, and a second authenticate-chain gate is one more thing
     * to reason about.
     */
    private static function renderWflsCleanupCard(): void
    {
        if (! WflsMigrator::readyToRemove()) {
            return;
        }
        ?>
        <div class="clockwork-card" style="border-left:4px solid #00753d;margin-bottom:16px;">
            <div class="clockwork-card__body" style="background:#f0fdf4;">
                <h2 style="margin-top:0;"><?php esc_html_e('Wordfence Login Security can now be removed', 'clockwork-renegade'); ?></h2>
                <p style="color:#50575e;"><?php esc_html_e('Every account that had two-factor in Wordfence Login Security has been migrated to Clockwork — nothing depends on it anymore. Wordfence is discontinuing the plugin, so this is the moment to remove it', 'clockwork-renegade'); ?><?php echo WflsMigrator::isWflsActive() ? '' : esc_html__(' (it is already deactivated; only the files remain)', 'clockwork-renegade'); ?>.</p>
                <?php self::actionForm(
                    'remove_wfls',
                    __('Deactivate & remove Wordfence Login Security', 'clockwork-renegade'),
                    'button button-primary',
                    __('Remove the Wordfence Login Security plugin? All two-factor setups have been migrated to Clockwork, so no account loses protection. This also runs the plugin\'s uninstall cleanup.', 'clockwork-renegade')
                ); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Roll-call of accounts that can change content or configuration.
     * Enrollment state per user: Clockwork 2FA / still on WFLS / none.
     */
    private static function renderTeamCard(): void
    {
        $users = get_users([
            'role__in' => ['administrator', 'editor'],
            'orderby' => 'display_name',
            'fields' => 'all',
        ]);
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__body">
            <h2 style="margin-top:0;"><?php esc_html_e('Team status', 'clockwork-renegade'); ?></h2>
            <p style="color:#50575e;"><?php esc_html_e('Administrator and editor accounts on this site, and where each one\'s two-factor protection stands.', 'clockwork-renegade'); ?></p>
            <table class="widefat striped" style="max-width:920px;">
                <thead>
                    <tr><th><?php esc_html_e('User', 'clockwork-renegade'); ?></th><th><?php esc_html_e('Role', 'clockwork-renegade'); ?></th><th><?php esc_html_e('Two-factor', 'clockwork-renegade'); ?></th><th><?php esc_html_e('Grace period', 'clockwork-renegade'); ?></th></tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u) : ?>
                    <?php
                    if (UserSettings::isEnabled($u->ID)) {
                        $pill = '<span style="color:#00753d;font-weight:600;">'.esc_html__('Clockwork 2FA', 'clockwork-renegade').'</span>';
                    } elseif (WflsMigrator::hasMigratable($u->ID)) {
                        $pill = WflsMigrator::isWflsActive()
                            ? '<span style="color:#996800;font-weight:600;">'.esc_html__('Wordfence (needs migration)', 'clockwork-renegade').'</span>'
                            : '<span style="color:#d63638;font-weight:600;">'.esc_html__('Wordfence — gate is OFF, migrate now', 'clockwork-renegade').'</span>';
                    } else {
                        $pill = '<span style="color:#787c82;">'.esc_html__('None', 'clockwork-renegade').'</span>';
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html($u->display_name); ?> <span style="color:#787c82;">(<?php echo esc_html($u->user_login); ?>)</span></td>
                        <td><?php echo esc_html(implode(', ', $u->roles)); ?></td>
                        <td><?php echo wp_kses_post($pill); ?></td>
                        <td><?php self::renderGraceCell($u->ID); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (WflsMigrator::isWflsActive() && ! WflsMigrator::readyToRemove()) : ?>
                <p style="color:#787c82;font-size:12px;margin-top:10px;">
                    <?php $remaining = WflsMigrator::remainingUnmigratedCount(); ?>
                    <?php
                    printf(
                        /* translators: %d: number of accounts still on Wordfence Login Security */
                        esc_html(_n(
                            '%d account still rely on Wordfence Login Security for two-factor. Once everyone has migrated, a removal button will appear here — deactivating WFLS earlier would silently remove their protection.',
                            '%d accounts still rely on Wordfence Login Security for two-factor. Once everyone has migrated, a removal button will appear here — deactivating WFLS earlier would silently remove their protection.',
                            $remaining,
                            'clockwork-renegade'
                        )),
                        (int) $remaining
                    );
                    ?>
                </p>
            <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Grace-period cell for one Team Status row. Only rendered as an
     * editable control for users EnrollmentNudge actually tracks (agency
     * email + manage_options + not yet enrolled) — everyone else (editors,
     * client accounts, already-enrolled users) just gets a dash, since the
     * nudge/enforcement never applies to them.
     */
    private static function renderGraceCell(int $userId): void
    {
        if (! EnrollmentNudge::isEligible($userId)) {
            echo '<span style="color:#c3c4c7;">—</span>';

            return;
        }

        $daysLeft = EnrollmentNudge::daysLeftFor($userId);
        $nonce = wp_create_nonce(EnrollmentNudge::graceNonceAction($userId));
        ?>
        <div style="color:<?php echo $daysLeft > 0 ? '#50575e' : '#d63638'; ?>;font-size:12px;margin-bottom:4px;">
            <?php echo $daysLeft > 0
                ? sprintf(
                    /* translators: %d: number of days left in the grace period */
                    esc_html(_n('%d day left', '%d days left', $daysLeft, 'clockwork-renegade')),
                    (int) $daysLeft
                )
                : '<strong>'.esc_html__('Expired — enforced now', 'clockwork-renegade').'</strong>'; ?>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:4px;align-items:center;">
            <input type="hidden" name="action" value="<?php echo esc_attr(EnrollmentNudge::SET_GRACE_ACTION); ?>">
            <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $userId); ?>">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
            <input type="number" name="days" min="0" value="<?php echo esc_attr((string) $daysLeft); ?>"
                   style="width:60px;" aria-label="<?php esc_attr_e('Days', 'clockwork-renegade'); ?>">
            <button type="submit" name="grace_op" value="set" class="button button-small"><?php esc_html_e('Set', 'clockwork-renegade'); ?></button>
            <button type="submit" name="grace_op" value="reset" class="button-link" style="font-size:12px;"><?php esc_html_e('Reset', 'clockwork-renegade'); ?></button>
        </form>
        <?php
    }

    private static function actionForm(string $op, string $label, string $buttonClass, string $confirm = '', string $spinnerLabel = ''): void
    {
        if ($confirm !== '') {
            $onSubmit = sprintf(' onsubmit="return confirm(%s);"', esc_attr(wp_json_encode($confirm)));
        } elseif ($spinnerLabel !== '') {
            $js = "var b=this.querySelector('button');b.disabled=true;b.classList.add('cwk-btn-loading');b.textContent=" . wp_json_encode($spinnerLabel) . ";";
            $onSubmit = ' onsubmit="' . esc_attr($js) . '"';
        } else {
            $onSubmit = '';
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;"<?php echo $onSubmit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_attr() ?>>
            <input type="hidden" name="action" value="<?php echo esc_attr(\ClockworkRenegade\Admin\Actions\TwoFactorActions::ACTION_HOOK); ?>">
            <input type="hidden" name="op" value="<?php echo esc_attr($op); ?>">
            <?php wp_nonce_field(\ClockworkRenegade\Admin\Actions\TwoFactorActions::NONCE_ACTION); ?>
            <button type="submit" class="<?php echo esc_attr($buttonClass); ?>"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    /** Text-link variant of actionForm for secondary operations. */
    private static function actionLink(string $op, string $label): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <input type="hidden" name="action" value="<?php echo esc_attr(\ClockworkRenegade\Admin\Actions\TwoFactorActions::ACTION_HOOK); ?>">
            <input type="hidden" name="op" value="<?php echo esc_attr($op); ?>">
            <?php wp_nonce_field(\ClockworkRenegade\Admin\Actions\TwoFactorActions::NONCE_ACTION); ?>
            <button type="submit" class="button-link" style="text-decoration:underline;color:inherit;font-size:inherit;"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }
}
