<?php

namespace ClockworkRenegade\Admin\Pages;

use ClockworkRenegade\Admin\Layout;
use ClockworkRenegade\Auth\Secret;

/**
 * Clockwork → Connection admin page.
 *
 * ManageWP-style pairing screen:
 * Displays site connection status, the base64-encoded Connection Key for one-click
 * enrollment in Clockwork Control, manual fallback credentials, and step-by-step
 * instructions.
 */
class ConnectionPage
{
    public const SLUG = 'clockwork-connection';

    public static function render(): void
    {
        Layout::render('connection', [self::class, 'renderBody']);
    }

    public static function renderBody(): void
    {
        $secret = Secret::ensure();
        $siteUrl = function_exists('home_url') ? home_url() : 'https://example.com';
        $connectionKey = base64_encode(function_exists('wp_json_encode') ? wp_json_encode([
            'url' => $siteUrl,
            'secret' => $secret,
            'variant' => 'renegade',
        ]) : json_encode([
            'url' => $siteUrl,
            'secret' => $secret,
            'variant' => 'renegade',
        ]));

        $lastContact = (int) get_option('clockwork_renegade_last_contact_at', 0);
        $isConnected = $lastContact > 0;

        Layout::pageHeader(
            __('Connection', 'clockwork-renegade'),
            __('Pair this WordPress site with your Clockwork Control dashboard.', 'clockwork-renegade')
        );

        ?>
        <div class="cwk-connection-wrap" style="max-width:840px;margin-top:20px;display:flex;flex-direction:column;gap:24px;">

            <!-- Status Banner -->
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
                <div style="display:flex;align-items:center;gap:16px;">
                    <div style="width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:<?php echo $isConnected ? '#ecfdf5' : '#fef3c7'; ?>;">
                        <?php if ($isConnected) : ?>
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 6L9 17l-5-5"/>
                            </svg>
                        <?php else : ?>
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div style="font-size:16px;font-weight:700;color:#1e293b;">
                            <?php echo $isConnected
                                ? esc_html__('Connected to Clockwork Control', 'clockwork-renegade')
                                : esc_html__('Awaiting Connection', 'clockwork-renegade'); ?>
                        </div>
                        <div style="font-size:13px;color:#64748b;margin-top:2px;">
                            <?php if ($isConnected) : ?>
                                <?php
                                $when = function_exists('human_time_diff')
                                    ? human_time_diff($lastContact)
                                    : (string) gmdate('Y-m-d H:i:s', $lastContact);
                                echo esc_html(
                                    function_exists('human_time_diff')
                                        ? sprintf(
                                            /* translators: %s: human-readable elapsed time */
                                            __('Last verified contact: %s ago', 'clockwork-renegade'),
                                            $when
                                        )
                                        : sprintf(
                                            /* translators: %s: timestamp */
                                            __('Last verified contact: %s', 'clockwork-renegade'),
                                            $when
                                        )
                                );
                                ?>
                            <?php else : ?>
                                <?php esc_html_e('This site is ready to pair. Copy the Connection Key below and paste it into Clockwork Control.', 'clockwork-renegade'); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div style="display:inline-flex;align-items:center;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600;background:<?php echo $isConnected ? '#d1fae5' : '#fef3c7'; ?>;color:<?php echo $isConnected ? '#065f46' : '#92400e'; ?>;">
                    <?php echo $isConnected
                        ? esc_html__('Active & Monitored', 'clockwork-renegade')
                        : esc_html__('Unlinked', 'clockwork-renegade'); ?>
                </div>
            </div>

            <!-- Connection Key Card -->
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:28px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                    <h2 style="font-size:18px;font-weight:700;color:#0f172a;margin:0;"><?php esc_html_e('Connection Key', 'clockwork-renegade'); ?></h2>
                    <span style="font-size:12px;color:#64748b;background:#f1f5f9;padding:4px 10px;border-radius:6px;"><?php esc_html_e('ManageWP Compatible', 'clockwork-renegade'); ?></span>
                </div>
                <p style="font-size:14px;color:#64748b;margin:0 0 16px 0;line-height:1.5;">
                    <?php
                    echo wp_kses(
                        __('Copy this key and paste it into the <strong>+ Add Site</strong> modal in your Clockwork Control dashboard. It securely encodes this site\'s URL and authentication secret.', 'clockwork-renegade'),
                        ['strong' => []]
                    );
                    ?>
                </p>

                <div style="position:relative;margin-bottom:16px;">
                    <input type="text" id="cwk-connection-key" readonly value="<?php echo esc_attr($connectionKey); ?>"
                           style="width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:13px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:12px 14px;color:#334155;box-sizing:border-box;"
                           onclick="this.select();" />
                </div>

                <div style="display:flex;align-items:center;gap:12px;">
                    <button type="button" id="cwk-copy-key-btn" onclick="cwkCopyConnectionKey()"
                            style="background:#2563eb;color:#ffffff;border:none;border-radius:8px;padding:10px 20px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:background 0.15s ease;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                        </svg>
                        <span id="cwk-copy-btn-text"><?php esc_html_e('Copy Connection Key', 'clockwork-renegade'); ?></span>
                    </button>
                    <span id="cwk-copy-status" style="font-size:13px;font-weight:600;color:#059669;display:none;">
                        <?php esc_html_e('Copied to clipboard!', 'clockwork-renegade'); ?>
                    </span>
                </div>
            </div>

            <!-- Manual Details Card -->
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:0 0 16px 0;"><?php esc_html_e('Manual Connection Credentials', 'clockwork-renegade'); ?></h3>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:12px;font-size:13px;align-items:center;">
                    <div style="font-weight:600;color:#64748b;"><?php esc_html_e('Site URL', 'clockwork-renegade'); ?></div>
                    <div><code style="background:#f1f5f9;padding:3px 8px;border-radius:4px;color:#0f172a;font-size:13px;"><?php echo esc_html($siteUrl); ?></code></div>

                    <div style="font-weight:600;color:#64748b;"><?php esc_html_e('Shared secret', 'clockwork-renegade'); ?></div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <code id="cwk-secret-val" style="background:#f1f5f9;padding:3px 8px;border-radius:4px;color:#0f172a;font-size:13px;">••••••••••••••••••••••••••••••••</code>
                        <button type="button" onclick="cwkToggleSecret('<?php echo esc_js($secret); ?>')" style="background:none;border:none;color:#2563eb;font-size:12px;cursor:pointer;text-decoration:underline;padding:0;"><?php esc_html_e('Show', 'clockwork-renegade'); ?></button>
                    </div>

                    <div style="font-weight:600;color:#64748b;"><?php esc_html_e('Plugin Version', 'clockwork-renegade'); ?></div>
                    <div><span style="color:#0f172a;"><?php echo esc_html(defined('CLOCKWORK_RENEGADE_VERSION') ? CLOCKWORK_RENEGADE_VERSION : '1.0.0'); ?></span></div>
                </div>
            </div>

            <!-- Instructions Card -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:24px;">
                <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:0 0 12px 0;"><?php esc_html_e('How to Pair with Clockwork Control', 'clockwork-renegade'); ?></h3>
                <ol style="margin:0;padding-left:20px;color:#475569;font-size:14px;line-height:1.7;">
                    <li><?php echo wp_kses(__('Click <strong>Copy Connection Key</strong> above.', 'clockwork-renegade'), ['strong' => []]); ?></li>
                    <li><?php echo wp_kses(__('Open your Clockwork Control dashboard, go to <strong>Sites</strong>, and click <strong>+ Add Site</strong>.', 'clockwork-renegade'), ['strong' => []]); ?></li>
                    <li><?php echo wp_kses(__('Paste the Connection Key and click <strong>Verify &amp; Connect</strong>. Clockwork will verify HMAC communication and immediately enroll your site for monitoring and direct S3 Glacier backups.', 'clockwork-renegade'), ['strong' => []]); ?></li>
                </ol>
            </div>

        </div>

        <script>
        function cwkCopyConnectionKey() {
            var copyInput = document.getElementById('cwk-connection-key');
            copyInput.select();
            copyInput.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(copyInput.value).then(function() {
                var status = document.getElementById('cwk-copy-status');
                var btnText = document.getElementById('cwk-copy-btn-text');
                btnText.textContent = <?php echo wp_json_encode(__('Copied!', 'clockwork-renegade')); ?>;
                status.style.display = 'inline';
                setTimeout(function() {
                    btnText.textContent = <?php echo wp_json_encode(__('Copy Connection Key', 'clockwork-renegade')); ?>;
                    status.style.display = 'none';
                }, 3000);
            });
        }

        var cwkSecretRevealed = false;
        function cwkToggleSecret(realSecret) {
            var el = document.getElementById('cwk-secret-val');
            var btn = event.target;
            if (! cwkSecretRevealed) {
                el.textContent = realSecret;
                btn.textContent = <?php echo wp_json_encode(__('Hide', 'clockwork-renegade')); ?>;
                cwkSecretRevealed = true;
            } else {
                el.textContent = '••••••••••••••••••••••••••••••••';
                btn.textContent = <?php echo wp_json_encode(__('Show', 'clockwork-renegade')); ?>;
                cwkSecretRevealed = false;
            }
        }
        </script>
        <?php
    }
}
