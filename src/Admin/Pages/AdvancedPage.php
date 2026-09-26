<?php

namespace ClockworkRenegade\Admin\Pages;

use ClockworkRenegade\Admin\Layout;
use ClockworkRenegade\Admin\Menu;
use ClockworkRenegade\Gatekeeper\Settings as GatekeeperSettings;
use ClockworkRenegade\Gatekeeper\Store as GatekeeperStore;
use ClockworkRenegade\WhiteLabel\WhiteLabel;

/**
 * Clockwork → Advanced admin page.
 *
 * Houses developer, security engine, and infrastructure tools under a
 * secondary pill-nav structure:
 *  - Gatekeeper: Native brute-force rate limiter, active lockouts, unlock actions
 *  - Connection: Clockwork Control pairing key and HMAC secret
 *  - Updates: Paused updates and coverage exceptions
 *  - Notifications: Webhook delivery and routing
 *  - Caching & Compat: Object cache status/flush and compatibility guards
 *  - Diagnostics: Server environment, PHP/MySQL specifications, system report
 */
class AdvancedPage
{
    public const SLUG = 'clockwork-advanced';

    /**
     * @return array<int, array{slug: string, label: string}>
     */
    public static function subtabs(): array
    {
        return [
            ['slug' => 'gatekeeper', 'label' => __('Gatekeeper', 'clockwork-renegade')],
            ['slug' => 'connection', 'label' => __('Connection', 'clockwork-renegade')],
            ['slug' => 'updates', 'label' => __('Update coverage', 'clockwork-renegade')],
            ['slug' => 'notifications', 'label' => __('Notifications', 'clockwork-renegade')],
            ['slug' => 'caching', 'label' => __('Caching & Compat', 'clockwork-renegade')],
            ['slug' => 'diagnostics', 'label' => __('Diagnostics', 'clockwork-renegade')],
        ];
    }

    public static function render(?string $forcedTab = null): void
    {
        $allowed = array_column(self::subtabs(), 'slug');
        if ($forcedTab !== null && $forcedTab !== '' && in_array($forcedTab, $allowed, true)) {
            $tab = $forcedTab;
        } elseif (isset($_GET['tab']) && is_string($_GET['tab'])) {
            $cleaned = function_exists('sanitize_key') ? sanitize_key($_GET['tab']) : strtolower(preg_replace('/[^a-z0-9_\-]/', '', $_GET['tab']));
            $tab = in_array($cleaned, $allowed, true) ? $cleaned : 'gatekeeper';
        } else {
            $tab = 'gatekeeper';
        }

        Layout::render('advanced', function () use ($tab) {
            self::renderContent($tab);
        });
    }

    public static function renderContent(string $activeTab): void
    {
        Layout::pageHeader(
            __('Advanced Settings', 'clockwork-renegade'),
            __('Fine-tune security engines, control plane connections, update rules, and diagnostics.', 'clockwork-renegade')
        );

        Layout::renderSubtabs(self::subtabs(), $activeTab, self::SLUG);

        switch ($activeTab) {
            case 'connection':
                ConnectionPage::renderBody();
                break;
            case 'updates':
                UpdateCoveragePage::renderBody();
                break;
            case 'notifications':
                NotificationsPage::renderBody();
                break;
            case 'caching':
                self::renderCachingBody();
                break;
            case 'diagnostics':
                self::renderDiagnosticsBody();
                break;
            case 'gatekeeper':
            default:
                self::renderGatekeeperBody();
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Gatekeeper Sub-Tab
    // -------------------------------------------------------------------------

    private static function renderGatekeeperBody(): void
    {
        $isAgency = Menu::currentUserIsAgency();
        $settings = GatekeeperSettings::get();
        $lockouts = GatekeeperStore::activeLockouts();
        $saved = isset($_GET['saved']) && $_GET['saved'] === '1';
        $unlocked = isset($_GET['unlocked']) ? sanitize_text_field((string) $_GET['unlocked']) : null;
        $unlockedAll = isset($_GET['unlocked_all']) && $_GET['unlocked_all'] === '1';

        $headline = $settings['headline'] ?? 'Too many failed login attempts';
        $body = $settings['body'] ?? 'Please wait {duration} before trying again.';
        $supportEmail = $settings['support_email'] ?? '';
        $supportUrl = $settings['support_url'] ?? '';
        $threshold = (int) ($settings['threshold'] ?? 4);
        $windowMinutes = (int) round(((int) ($settings['window_seconds'] ?? 1200)) / 60);
        $lockoutMinutes = (int) round(((int) ($settings['lockout_seconds'] ?? 1200)) / 60);
        $consecutiveExtended = (int) ($settings['consecutive_lockouts_for_extended'] ?? 4);
        $extendedHours = (int) round(((int) ($settings['extended_lockout_seconds'] ?? 86400)) / 3600);
        $enabled = (bool) ($settings['enabled'] ?? false);
        $ignoreIps = implode("\n", (array) ($settings['ignore_ips'] ?? []));
        $ignoreCidrs = implode("\n", (array) ($settings['ignore_cidrs'] ?? []));
        ?>

        <?php if (! $isAgency) : ?>
            <div class="notice notice-info" style="margin: 0 0 20px 0; border-radius: 6px; padding: 12px 16px; background:#eff6ff; border-left:4px solid #2563eb;">
                <p style="margin:0; font-weight:600; color:#1e3a8a;"><?php esc_html_e('Security Policies Managed by Agency', 'clockwork-renegade'); ?></p>
                <p style="margin:4px 0 0 0; font-size:13px; color:#1e40af;">
                    <?php esc_html_e('Gatekeeper brute-force protection and IP rules are actively managed by your agency. Settings below are displayed in read-only mode.', 'clockwork-renegade'); ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if ($saved) : ?>
            <div class="notice notice-success is-dismissible" style="margin: 0 0 20px 0; border-radius: 6px;">
                <p><strong><?php esc_html_e('Gatekeeper settings updated successfully.', 'clockwork-renegade'); ?></strong></p>
            </div>
        <?php endif; ?>

        <?php if ($unlocked) : ?>
            <div class="notice notice-success is-dismissible" style="margin: 0 0 20px 0; border-radius: 6px;">
                <p><?php echo esc_html(sprintf(__('IP address %s has been unlocked.', 'clockwork-renegade'), $unlocked)); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($unlockedAll) : ?>
            <div class="notice notice-success is-dismissible" style="margin: 0 0 20px 0; border-radius: 6px;">
                <p><?php esc_html_e('All active IP lockouts have been cleared.', 'clockwork-renegade'); ?></p>
            </div>
        <?php endif; ?>

        <div style="display:flex; flex-direction:column; gap:24px; max-width:960px;">

            <!-- Active Lockouts Panel -->
            <div class="clockwork-card" style="padding: 24px; background:#fff; border: 1px solid var(--cwk-border); border-radius:8px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <div>
                        <h2 style="font-size:16px; font-weight:600; margin:0 0 4px 0; color:var(--cwk-text);">
                            <?php esc_html_e('Active Lockouts', 'clockwork-renegade'); ?>
                            <span style="font-size:12px; font-weight:500; background:#f1f5f9; padding:2px 8px; border-radius:12px; margin-left:8px; color:#475569;">
                                <?php echo count($lockouts); ?>
                            </span>
                        </h2>
                        <p style="margin:0; font-size:13px; color:var(--cwk-text-muted);">
                            <?php esc_html_e('IP addresses currently blocked from authenticating due to excessive failed attempts.', 'clockwork-renegade'); ?>
                        </p>
                    </div>

                    <?php if (! empty($lockouts) && $isAgency) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Clear all active lockouts immediately?', 'clockwork-renegade')); ?>');">
                            <?php wp_nonce_field('clockwork_gatekeeper_unlock_all'); ?>
                            <input type="hidden" name="action" value="clockwork_gatekeeper_unlock_all" />
                            <button type="submit" class="button button-secondary" style="color:#b91c1c; border-color:#fca5a5;">
                                <?php esc_html_e('Unlock All IPs', 'clockwork-renegade'); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if (empty($lockouts)) : ?>
                    <div style="padding:16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; font-size:13px; color:#64748b; display:flex; align-items:center; gap:8px;">
                        <span style="color:#10b981; font-weight:bold;">✓</span>
                        <?php esc_html_e('No IP addresses are currently locked out. Login endpoints are operating normally.', 'clockwork-renegade'); ?>
                    </div>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped" style="border-radius:6px; overflow:hidden;">
                        <thead>
                            <tr>
                                <th style="font-weight:600;"><?php esc_html_e('IP Address', 'clockwork-renegade'); ?></th>
                                <th style="font-weight:600;"><?php esc_html_e('Unlock Time (UTC)', 'clockwork-renegade'); ?></th>
                                <th style="font-weight:600; width:120px;"><?php esc_html_e('Action', 'clockwork-renegade'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lockouts as $item) : ?>
                                <?php
                                $ip = (string) ($item['ip'] ?? '');
                                $unlockAt = (string) ($item['unlock_at'] ?? '');
                                $unlockTs = strtotime($unlockAt);
                                $remainingMins = $unlockTs ? max(1, (int) round(($unlockTs - time()) / 60)) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <code style="font-size:13px;"><?php echo esc_html($ip); ?></code>
                                    </td>
                                    <td>
                                        <?php if ($unlockTs) : ?>
                                            <span><?php echo esc_html(gmdate('Y-m-d H:i:s', $unlockTs)); ?></span>
                                            <span style="color:#64748b; font-size:12px; margin-left:6px;">(<?php printf(esc_html__('in %d min', 'clockwork-renegade'), $remainingMins); ?>)</span>
                                        <?php else : ?>
                                            <span><?php esc_html_e('Standard window', 'clockwork-renegade'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <?php wp_nonce_field('clockwork_gatekeeper_unlock_' . $ip); ?>
                                            <input type="hidden" name="action" value="clockwork_gatekeeper_unlock_ip" />
                                            <input type="hidden" name="ip" value="<?php echo esc_attr($ip); ?>" />
                                            <button type="submit" class="button button-small" style="font-size:12px;">
                                                <?php esc_html_e('Unlock', 'clockwork-renegade'); ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Configuration Form -->
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('clockwork_gatekeeper_save'); ?>
                <input type="hidden" name="action" value="clockwork_gatekeeper_save_settings" />

                <div class="clockwork-card" style="padding: 24px; background:#fff; border: 1px solid var(--cwk-border); border-radius:8px; display:flex; flex-direction:column; gap:20px;">
                    <h2 style="font-size:16px; font-weight:600; margin:0 0 4px 0; color:var(--cwk-text);">
                        <?php esc_html_e('Protection Settings', 'clockwork-renegade'); ?>
                    </h2>

                    <!-- Toggle Enabled -->
                    <label style="display:flex; align-items:center; gap:10px; cursor:<?php echo $isAgency ? 'pointer' : 'default'; ?>;">
                        <input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?> <?php disabled(! $isAgency); ?> style="width:18px; height:18px;" />
                        <span style="font-weight:600; font-size:14px; color:var(--cwk-text);">
                            <?php esc_html_e('Enable Gatekeeper Brute-Force Protection', 'clockwork-renegade'); ?>
                        </span>
                    </label>

                    <hr style="border:none; border-top:1px solid #e2e8f0; margin:4px 0;" />

                    <!-- Attempt Thresholds -->
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                        <div>
                            <label style="display:block; font-weight:600; font-size:13px; margin-bottom:4px; color:var(--cwk-text);">
                                <?php esc_html_e('Failed Attempt Threshold', 'clockwork-renegade'); ?>
                            </label>
                            <input type="number" min="1" max="50" name="threshold" value="<?php echo esc_attr((string) $threshold); ?>" <?php disabled(! $isAgency); ?> class="regular-text" style="width:100%; max-width:200px;" />
                            <p style="margin:4px 0 0 0; font-size:12px; color:var(--cwk-text-muted);">
                                <?php esc_html_e('Number of allowed bad passwords before lockout (default: 4).', 'clockwork-renegade'); ?>
                            </p>
                        </div>

                        <div>
                            <label style="display:block; font-weight:600; font-size:13px; margin-bottom:4px; color:var(--cwk-text);">
                                <?php esc_html_e('Attempt Count Window (Minutes)', 'clockwork-renegade'); ?>
                            </label>
                            <input type="number" min="1" max="1440" name="window_minutes" value="<?php echo esc_attr((string) $windowMinutes); ?>" <?php disabled(! $isAgency); ?> class="regular-text" style="width:100%; max-width:200px;" />
                            <p style="margin:4px 0 0 0; font-size:12px; color:var(--cwk-text-muted);">
                                <?php esc_html_e('Time window before failed attempt counters reset (default: 20 min).', 'clockwork-renegade'); ?>
                            </p>
                        </div>
                    </div>

                    <!-- Lockout Durations -->
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                        <div>
                            <label style="display:block; font-weight:600; font-size:13px; margin-bottom:4px; color:var(--cwk-text);">
                                <?php esc_html_e('Standard Lockout Duration (Minutes)', 'clockwork-renegade'); ?>
                            </label>
                            <input type="number" min="1" max="10080" name="lockout_minutes" value="<?php echo esc_attr((string) $lockoutMinutes); ?>" <?php disabled(! $isAgency); ?> class="regular-text" style="width:100%; max-width:200px;" />
                            <p style="margin:4px 0 0 0; font-size:12px; color:var(--cwk-text-muted);">
                                <?php esc_html_e('Duration an IP is blocked on initial lockout (default: 20 min).', 'clockwork-renegade'); ?>
                            </p>
                        </div>

                        <div>
                            <label style="display:block; font-weight:600; font-size:13px; margin-bottom:4px; color:var(--cwk-text);">
                                <?php esc_html_e('Extended Lockout Trigger (Consecutive Lockouts)', 'clockwork-renegade'); ?>
                            </label>
                            <input type="number" min="1" max="20" name="consecutive_lockouts_for_extended" value="<?php echo esc_attr((string) $consecutiveExtended); ?>" <?php disabled(! $isAgency); ?> class="regular-text" style="width:100%; max-width:200px;" />
                            <p style="margin:4px 0 0 0; font-size:12px; color:var(--cwk-text-muted);">
                                <?php esc_html_e('Repeat lockouts required to trigger extended ban (default: 4).', 'clockwork-renegade'); ?>
                            </p>
                        </div>
                    </div>

                    <div>
                        <label style="display:block; font-weight:600; font-size:13px; margin-bottom:4px; color:var(--cwk-text);">
                            <?php esc_html_e('Extended Lockout Duration (Hours)', 'clockwork-renegade'); ?>
                        </label>
                        <input type="number" min="1" max="720" name="extended_lockout_hours" value="<?php echo esc_attr((string) $extendedHours); ?>" <?php disabled(! $isAgency); ?> class="regular-text" style="width:100%; max-width:200px;" />
                        <p style="margin:4px 0 0 0; font-size:12px; color:var(--cwk-text-muted);">
                            <?php esc_html_e('Extended duration for persistent offenders (default: 24 hours).', 'clockwork-renegade'); ?>
                        </p>
                    </div>

                    <hr style="border:none; border-top:1px solid #e2e8f0; margin:4px 0;" />

                    <!-- Custom Lockout Notice -->
                    <div>
                        <h3 style="font-size:14px; font-weight:600; margin:0 0 8px 0; color:var(--cwk-text);">
                            <?php esc_html_e('Lockout Screen Messaging', 'clockwork-renegade'); ?>
                        </h3>
                        <div style="margin-bottom:16px;">
                            <label style="display:block; font-weight:500; font-size:13px; margin-bottom:4px; color:var(--cwk-text);">
                                <?php esc_html_e('Headline', 'clockwork-renegade'); ?>
                            </label>
                            <input type="text" name="headline" value="<?php echo esc_attr($headline); ?>" <?php disabled(! $isAgency); ?> class="regular-text" style="width:100%; max-width:540px;" />
                        </div>

                        <div style="margin-bottom:16px;">
                            <label style="display:block; font-weight:500; font-size:13px; margin-bottom:4px; color:var(--cwk-text);">
                                <?php esc_html_e('Message Body', 'clockwork-renegade'); ?>
                            </label>
                            <textarea name="body" rows="3" class="large-text" <?php disabled(! $isAgency); ?> style="width:100%; max-width:540px;"><?php echo esc_textarea($body); ?></textarea>
                            <p style="margin:4px 0 0 0; font-size:12px; color:var(--cwk-text-muted);">
                                <?php esc_html_e('Supports placeholder {duration} for remaining lockout countdown.', 'clockwork-renegade'); ?>
                            </p>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                            <div>
                                <label style="display:block; font-weight:500; font-size:13px; margin-bottom:4px; color:var(--cwk-text);">
                                    <?php esc_html_e('Support Email (Optional)', 'clockwork-renegade'); ?>
                                </label>
                                <input type="email" name="support_email" value="<?php echo esc_attr($supportEmail); ?>" <?php disabled(! $isAgency); ?> class="regular-text" style="width:100%; max-width:320px;" placeholder="support@example.com" />
                            </div>

                            <div>
                                <label style="display:block; font-weight:500; font-size:13px; margin-bottom:4px; color:var(--cwk-text);">
                                    <?php esc_html_e('Support URL (Optional)', 'clockwork-renegade'); ?>
                                </label>
                                <input type="url" name="support_url" value="<?php echo esc_attr($supportUrl); ?>" <?php disabled(! $isAgency); ?> class="regular-text" style="width:100%; max-width:320px;" placeholder="https://example.com/support" />
                            </div>
                        </div>
                    </div>

                    <hr style="border:none; border-top:1px solid #e2e8f0; margin:4px 0;" />

                    <!-- IP Allowlist -->
                    <div>
                        <h3 style="font-size:14px; font-weight:600; margin:0 0 8px 0; color:var(--cwk-text);">
                            <?php esc_html_e('Allowlisted IP Addresses & Subnets', 'clockwork-renegade'); ?>
                        </h3>
                        <p style="margin:0 0 8px 0; font-size:12px; color:var(--cwk-text-muted);">
                            <?php esc_html_e('These IPs are never throttled or locked out. One per line. Private local networks (127.0.0.1, 10.0.0.0/8, 192.168.0.0/16, etc.) are always automatically whitelisted.', 'clockwork-renegade'); ?>
                        </p>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                            <div>
                                <label style="display:block; font-weight:500; font-size:12px; margin-bottom:4px;">
                                    <?php esc_html_e('Individual IPs (e.g. 198.51.100.1)', 'clockwork-renegade'); ?>
                                </label>
                                <textarea name="ignore_ips" rows="4" <?php disabled(! $isAgency); ?> style="width:100%; font-family:monospace; font-size:12px;"><?php echo esc_textarea($ignoreIps); ?></textarea>
                            </div>

                            <div>
                                <label style="display:block; font-weight:500; font-size:12px; margin-bottom:4px;">
                                    <?php esc_html_e('CIDR Subnets (e.g. 198.51.100.0/24)', 'clockwork-renegade'); ?>
                                </label>
                                <textarea name="ignore_cidrs" rows="4" <?php disabled(! $isAgency); ?> style="width:100%; font-family:monospace; font-size:12px;"><?php echo esc_textarea($ignoreCidrs); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <?php if ($isAgency) : ?>
                        <div style="margin-top:8px;">
                            <button type="submit" class="button button-primary" style="padding:6px 18px; font-size:13px; height:auto;">
                                <?php esc_html_e('Save Gatekeeper Settings', 'clockwork-renegade'); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Caching & Compatibility Sub-Tab
    // -------------------------------------------------------------------------

    private static function renderCachingBody(): void
    {
        $isAgency = Menu::currentUserIsAgency();
        $hasObjectCache = wp_using_ext_object_cache();
        $cacheFlushed = isset($_GET['flushed']) && $_GET['flushed'] === '1';
        ?>

        <?php if ($cacheFlushed) : ?>
            <div class="notice notice-success is-dismissible" style="margin: 0 0 20px 0; border-radius: 6px;">
                <p><strong><?php esc_html_e('Persistent object cache flushed successfully.', 'clockwork-renegade'); ?></strong></p>
            </div>
        <?php endif; ?>

        <div style="display:flex; flex-direction:column; gap:24px; max-width:960px;">

            <!-- Object Cache Status Card -->
            <div class="clockwork-card" style="padding: 24px; background:#fff; border: 1px solid var(--cwk-border); border-radius:8px;">
                <h2 style="font-size:16px; font-weight:600; margin:0 0 8px 0; color:var(--cwk-text);">
                    <?php esc_html_e('Object Cache Engine', 'clockwork-renegade'); ?>
                </h2>

                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px; padding:16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                    <div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:<?php echo $hasObjectCache ? '#10b981' : '#64748b'; ?>;"></span>
                            <span style="font-weight:600; font-size:14px; color:var(--cwk-text);">
                                <?php echo $hasObjectCache ? esc_html__('External Object Cache Active (Redis / Memcached)', 'clockwork-renegade') : esc_html__('Standard Database Transient Storage', 'clockwork-renegade'); ?>
                            </span>
                        </div>
                        <p style="margin:4px 0 0 18px; font-size:12px; color:var(--cwk-text-muted);">
                            <?php echo $hasObjectCache
                                ? esc_html__('Gatekeeper attempt counters and lockout lookups run in-memory via Redis/Memcached with sub-millisecond overhead.', 'clockwork-renegade')
                                : esc_html__('Transient and rate-limiting counters are safely stored in your WordPress database.', 'clockwork-renegade'); ?>
                        </p>
                    </div>

                    <?php if ($hasObjectCache && $isAgency) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Flushing the object cache will drop in-memory rate-limiting counters. Persistent lockouts in MySQL remain active. Continue?', 'clockwork-renegade')); ?>');">
                            <?php wp_nonce_field('clockwork_flush_cache'); ?>
                            <input type="hidden" name="action" value="clockwork_flush_cache" />
                            <button type="submit" class="button button-secondary" style="font-size:13px;">
                                <?php esc_html_e('Flush Object Cache', 'clockwork-renegade'); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Compatibility Shields Card -->
            <div class="clockwork-card" style="padding: 24px; background:#fff; border: 1px solid var(--cwk-border); border-radius:8px;">
                <h2 style="font-size:16px; font-weight:600; margin:0 0 4px 0; color:var(--cwk-text);">
                    <?php esc_html_e('Compatibility Shields', 'clockwork-renegade'); ?>
                </h2>
                <p style="margin:0 0 16px 0; font-size:13px; color:var(--cwk-text-muted);">
                    <?php esc_html_e('Clockwork automatically negotiates compatibility with common caching and security plugins.', 'clockwork-renegade'); ?>
                </p>

                <div style="display:flex; flex-direction:column; gap:12px;">
                    <div style="padding:12px 16px; border:1px solid #e2e8f0; border-radius:6px; display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <strong>Perfmatters Compatibility</strong>
                            <div style="font-size:12px; color:#64748b;"><?php esc_html_e('Protects Clockwork scripts from deferred/delayed JS execution breaking admin dashboards.', 'clockwork-renegade'); ?></div>
                        </div>
                        <span style="font-size:12px; font-weight:600; color:#10b981;"><?php esc_html_e('Guarded', 'clockwork-renegade'); ?></span>
                    </div>

                    <div style="padding:12px 16px; border:1px solid #e2e8f0; border-radius:6px; display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <strong>WP Force Login Compatibility</strong>
                            <div style="font-size:12px; color:#64748b;"><?php esc_html_e('Permits signed HMAC control plane REST requests and SSO sessions through authentication barriers.', 'clockwork-renegade'); ?></div>
                        </div>
                        <span style="font-size:12px; font-weight:600; color:#10b981;"><?php esc_html_e('Guarded', 'clockwork-renegade'); ?></span>
                    </div>

                    <div style="padding:12px 16px; border:1px solid #e2e8f0; border-radius:6px; display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <strong>Elementor Cache Guard</strong>
                            <div style="font-size:12px; color:#64748b;"><?php esc_html_e('Automatically triggers Elementor CSS regenerations following automated plugin updates.', 'clockwork-renegade'); ?></div>
                        </div>
                        <span style="font-size:12px; font-weight:600; color:#10b981;"><?php esc_html_e('Guarded', 'clockwork-renegade'); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Diagnostics Sub-Tab
    // -------------------------------------------------------------------------

    private static function renderDiagnosticsBody(): void
    {
        global $wp_version, $wpdb;

        $wpVer = (string) ($wp_version ?? 'Unknown');
        $phpVersion = PHP_VERSION;
        $memoryLimit = ini_get('memory_limit') ?: 'N/A';
        $maxExecution = ini_get('max_execution_time') ?: 'N/A';
        $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
        $mysqlVersion = $wpdb->db_version() ?: 'Unknown';
        $curlVersion = function_exists('curl_version') ? (curl_version()['version'] ?? 'N/A') : 'Unavailable';
        $openSsl = defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'N/A';

        // Additional read-only diagnostics suggested by Grok review
        $gkSettings = GatekeeperSettings::get();
        $gkEnabled = ! empty($gkSettings['enabled']) ? 'Enabled' : 'Disabled';
        $gkStore = wp_using_ext_object_cache() ? 'In-Memory Object Cache (Redis/Memcached)' : 'Relational Database (MySQL)';
        $gkLockoutsCount = count(GatekeeperStore::activeLockouts());

        $hasDropIn = defined('WP_CONTENT_DIR') && file_exists(WP_CONTENT_DIR . '/object-cache.php');
        $objectCacheStatus = (wp_using_ext_object_cache() ? 'Active' : 'Inactive') . ($hasDropIn ? ' (object-cache.php drop-in present)' : '');

        $wpDebug = (defined('WP_DEBUG') && constant('WP_DEBUG')) ? 'True' : 'False';
        $disallowFileEdit = (defined('DISALLOW_FILE_EDIT') && constant('DISALLOW_FILE_EDIT')) ? 'True' : 'False';
        $updaterDisabled = (defined('AUTOMATIC_UPDATER_DISABLED') && constant('AUTOMATIC_UPDATER_DISABLED')) ? 'True' : 'False';

        // Check defined Clockwork constants
        $definedFlags = [];
        foreach (['CLOCKWORK_GATEKEEPER_DISABLE', 'CLOCKWORK_2FA_DISABLE', 'CLOCKWORK_RENEGADE_TRUST_PROXY', 'CLOCKWORK_UNLOCK_HUB'] as $const) {
            if (defined($const) && constant($const)) {
                $definedFlags[] = $const;
            }
        }
        $flagsDisplay = ! empty($definedFlags) ? implode(', ', $definedFlags) : 'None';

        // Next 5 scheduled Clockwork crons
        $cronEvents = [];
        if (function_exists('_get_cron_array')) {
            $crons = _get_cron_array();
            if (is_array($crons)) {
                foreach ($crons as $timestamp => $hooks) {
                    if (is_array($hooks)) {
                        foreach ($hooks as $hook => $data) {
                            if (str_starts_with((string) $hook, 'clockwork_')) {
                                $diff = function_exists('human_time_diff') ? human_time_diff((int) $timestamp) : ((int) $timestamp - time()) . 's';
                                $cronEvents[] = "{$hook} (in {$diff})";
                                if (count($cronEvents) >= 5) {
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
        }
        $cronsDisplay = ! empty($cronEvents) ? implode("\n", $cronEvents) : 'No scheduled Clockwork crons found';

        $reportText = "### System Diagnostics\n"
            . "- WordPress: {$wpVer}\n"
            . "- PHP: {$phpVersion}\n"
            . "- Memory Limit: {$memoryLimit}\n"
            . "- Max Execution Time: {$maxExecution}s\n"
            . "- Database: MySQL {$mysqlVersion}\n"
            . "- Web Server: {$serverSoftware}\n"
            . "- cURL: {$curlVersion}\n"
            . "- OpenSSL: {$openSsl}\n"
            . "- Object Cache: {$objectCacheStatus}\n"
            . "- Gatekeeper: {$gkEnabled} (Store: {$gkStore}, Active lockouts: {$gkLockoutsCount})\n"
            . "- Core Flags: WP_DEBUG={$wpDebug}, DISALLOW_FILE_EDIT={$disallowFileEdit}, AUTOMATIC_UPDATER_DISABLED={$updaterDisabled}\n"
            . "- Active Feature Constants: {$flagsDisplay}\n";
        ?>

        <div style="display:flex; flex-direction:column; gap:24px; max-width:960px;">
            <div class="clockwork-card" style="padding: 24px; background:#fff; border: 1px solid var(--cwk-border); border-radius:8px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <div>
                        <h2 style="font-size:16px; font-weight:600; margin:0 0 4px 0; color:var(--cwk-text);">
                            <?php esc_html_e('System Environment', 'clockwork-renegade'); ?>
                        </h2>
                        <p style="margin:0; font-size:13px; color:var(--cwk-text-muted);">
                            <?php esc_html_e('Core runtime, security engine, and server environment details.', 'clockwork-renegade'); ?>
                        </p>
                    </div>

                    <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('cwkSystemReport').value); alert('<?php echo esc_js(__('System report copied to clipboard!', 'clockwork-renegade')); ?>');">
                        <?php esc_html_e('Copy System Report', 'clockwork-renegade'); ?>
                    </button>
                </div>

                <table class="widefat striped" style="border-radius:6px; overflow:hidden;">
                    <tbody>
                        <tr><td style="width:220px; font-weight:600;">WordPress Version</td><td><?php echo esc_html($wpVer); ?></td></tr>
                        <tr><td style="font-weight:600;">PHP Version</td><td><?php echo esc_html($phpVersion); ?></td></tr>
                        <tr><td style="font-weight:600;">PHP Memory Limit</td><td><?php echo esc_html($memoryLimit); ?></td></tr>
                        <tr><td style="font-weight:600;">Max Execution Time</td><td><?php echo esc_html($maxExecution); ?> seconds</td></tr>
                        <tr><td style="font-weight:600;">Database Engine</td><td>MySQL / MariaDB <?php echo esc_html($mysqlVersion); ?></td></tr>
                        <tr><td style="font-weight:600;">Web Server</td><td><?php echo esc_html($serverSoftware); ?></td></tr>
                        <tr><td style="font-weight:600;">cURL / OpenSSL</td><td><?php echo esc_html("cURL {$curlVersion} / {$openSsl}"); ?></td></tr>
                        <tr><td style="font-weight:600;">Gatekeeper Engine</td><td><?php echo esc_html("{$gkEnabled} · {$gkStore} ({$gkLockoutsCount} active lockouts)"); ?></td></tr>
                        <tr><td style="font-weight:600;">Object Cache</td><td><?php echo esc_html($objectCacheStatus); ?></td></tr>
                        <tr><td style="font-weight:600;">WP Core Constants</td><td><code>WP_DEBUG: <?php echo esc_html($wpDebug); ?></code> &middot; <code>DISALLOW_FILE_EDIT: <?php echo esc_html($disallowFileEdit); ?></code></td></tr>
                        <tr><td style="font-weight:600;">Active Feature Flags</td><td><code><?php echo esc_html($flagsDisplay); ?></code></td></tr>
                        <tr><td style="font-weight:600; vertical-align:top;">Next Clockwork Crons</td><td><pre style="margin:0; font-size:12px; background:transparent; padding:0;"><?php echo esc_html($cronsDisplay); ?></pre></td></tr>
                    </tbody>
                </table>

                <textarea id="cwkSystemReport" style="display:none;"><?php echo esc_textarea($reportText); ?></textarea>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // POST Action Handlers
    // -------------------------------------------------------------------------

    public static function handleSaveGatekeeper(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'clockwork-renegade'), 403);
        }

        if (! Menu::currentUserIsAgency()) {
            wp_die(esc_html__('Gatekeeper settings can only be modified by authorized agency administrators.', 'clockwork-renegade'), 403);
        }

        check_admin_referer('clockwork_gatekeeper_save');

        $ignoreIpsRaw = explode("\n", (string) ($_POST['ignore_ips'] ?? ''));
        $ignoreCidrsRaw = explode("\n", (string) ($_POST['ignore_cidrs'] ?? ''));

        $windowMins = max(1, min(1440, (int) ($_POST['window_minutes'] ?? 20)));
        $lockoutMins = max(1, min(10080, (int) ($_POST['lockout_minutes'] ?? 20)));
        $extendedHours = max(1, min(720, (int) ($_POST['extended_lockout_hours'] ?? 24)));

        GatekeeperSettings::update([
            'enabled' => ! empty($_POST['enabled']),
            'threshold' => max(1, min(50, (int) ($_POST['threshold'] ?? 4))),
            'window_seconds' => $windowMins * 60,
            'lockout_seconds' => $lockoutMins * 60,
            'consecutive_lockouts_for_extended' => max(1, min(20, (int) ($_POST['consecutive_lockouts_for_extended'] ?? 4))),
            'extended_lockout_seconds' => $extendedHours * 3600,
            'headline' => sanitize_text_field((string) ($_POST['headline'] ?? '')),
            'body' => sanitize_textarea_field((string) ($_POST['body'] ?? '')),
            'support_email' => sanitize_email((string) ($_POST['support_email'] ?? '')),
            'support_url' => esc_url_raw((string) ($_POST['support_url'] ?? '')),
            'ignore_ips' => $ignoreIpsRaw,
            'ignore_cidrs' => $ignoreCidrsRaw,
        ]);

        self::redirectAndExit(add_query_arg([
            'page'  => self::SLUG,
            'tab'   => 'gatekeeper',
            'saved' => '1',
        ], admin_url('admin.php')));
    }

    public static function handleUnlockIp(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'clockwork-renegade'), 403);
        }

        $ip = sanitize_text_field((string) ($_POST['ip'] ?? ''));
        check_admin_referer('clockwork_gatekeeper_unlock_' . $ip);

        if ($ip && ($ip === 'unknown' || filter_var($ip, FILTER_VALIDATE_IP))) {
            GatekeeperStore::unlockIp($ip);
        }

        self::redirectAndExit(add_query_arg([
            'page'     => self::SLUG,
            'tab'      => 'gatekeeper',
            'unlocked' => urlencode($ip),
        ], admin_url('admin.php')));
    }

    public static function handleUnlockAll(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'clockwork-renegade'), 403);
        }

        if (! Menu::currentUserIsAgency()) {
            wp_die(esc_html__('Bulk unlock can only be executed by authorized agency administrators.', 'clockwork-renegade'), 403);
        }

        check_admin_referer('clockwork_gatekeeper_unlock_all');

        GatekeeperStore::unlockAll();

        self::redirectAndExit(add_query_arg([
            'page'         => self::SLUG,
            'tab'          => 'gatekeeper',
            'unlocked_all' => '1',
        ], admin_url('admin.php')));
    }

    public static function handleFlushCache(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'clockwork-renegade'), 403);
        }

        if (! Menu::currentUserIsAgency()) {
            wp_die(esc_html__('Cache flushing can only be executed by authorized agency administrators.', 'clockwork-renegade'), 403);
        }

        check_admin_referer('clockwork_flush_cache');

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        self::redirectAndExit(add_query_arg([
            'page'    => self::SLUG,
            'tab'     => 'caching',
            'flushed' => '1',
        ], admin_url('admin.php')));
    }

    public static function redirectAndExit(string $location): void
    {
        wp_safe_redirect($location);
        if (PHP_SAPI !== "cli") {
            exit;
        }
    }
}
