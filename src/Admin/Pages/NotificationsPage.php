<?php

namespace ClockworkRenegade\Admin\Pages;

use ClockworkRenegade\Admin\Layout;
use ClockworkRenegade\Notifications\ClientNotifications;
use ClockworkRenegade\WhiteLabel\WhiteLabel;

/**
 * Notifications settings page — lets administrators view and configure where
 * Care Plan & Maintenance Reports and real-time Slack alerts are delivered.
 */
class NotificationsPage
{
    public const SLUG = 'clockwork-notifications';

    public static function render(): void
    {
        Layout::render('notifications', [self::class, 'renderBody']);
    }

    public static function renderBody(): void
    {
        $webhookUrl = ClientNotifications::getSlackWebhookUrl();
        $recipients = ClientNotifications::getReportRecipients();
        $frequency = ClientNotifications::getReportFrequency();
        $enabled = ClientNotifications::isReportEnabled();
        $lastSentAt = ClientNotifications::getReportLastSentAt();
        $saved = isset($_GET['saved']) && $_GET['saved'] === '1';
        $nonce = wp_create_nonce('clockwork_save_notifications');

        $adminUsers = function_exists('get_users')
            ? get_users([
                'role' => 'administrator',
                'fields' => ['user_email', 'display_name'],
                'number' => 25,
            ])
            : [];

        $adminSuggestions = [];
        foreach ($adminUsers as $u) {
            $email = isset($u->user_email) ? strtolower(trim((string) $u->user_email)) : '';
            if ($email !== '' && (! function_exists('is_email') || is_email($email))) {
                $adminSuggestions[$email] = isset($u->display_name) && $u->display_name !== '' ? (string) $u->display_name : $email;
            }
        }

        $lastSentFormatted = null;
        if ($lastSentAt !== null) {
            $ts = strtotime($lastSentAt);
            if ($ts !== false) {
                $lastSentFormatted = function_exists('wp_date')
                    ? wp_date(get_option('date_format', 'M j, Y'), $ts)
                    : gmdate('M j, Y', $ts);
            }
        }
        ?>
        <?php Layout::pageHeader(__('Notifications', 'clockwork-renegade'), __('Configure where Care Plan reports and site alerts are delivered.', 'clockwork-renegade')); ?>

        <?php if ($saved) : ?>
            <div class="notice notice-success is-dismissible" style="margin:16px 0;">
                <p><strong><?php esc_html_e('Notification settings saved.', 'clockwork-renegade'); ?></strong></p>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="clockwork-notifications-form">
            <input type="hidden" name="action" value="clockwork_save_notifications">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">

            <!-- Care Plan & Maintenance Reports Card -->
            <div class="clockwork-card" style="max-width:720px;margin-bottom:20px;">
                <div class="clockwork-card__head" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <h2 style="margin:0;"><?php esc_html_e('Care Plan & Maintenance Reports', 'clockwork-renegade'); ?></h2>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <?php if ($enabled && ! empty($recipients)) : ?>
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;background:#dcfce7;color:#166534;font-size:12px;font-weight:600;">
                                ● <?php echo esc_html(sprintf(__('Active · %s', 'clockwork-renegade'), ucfirst($frequency))); ?>
                            </span>
                        <?php elseif (! $enabled) : ?>
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;background:#f3f4f6;color:#4b5563;font-size:12px;font-weight:600;">
                                <?php esc_html_e('Paused', 'clockwork-renegade'); ?>
                            </span>
                        <?php else : ?>
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:12px;font-weight:600;">
                                <?php esc_html_e('No recipients configured', 'clockwork-renegade'); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($lastSentFormatted !== null) : ?>
                            <span style="font-size:12px;color:#6b7280;">
                                <?php echo esc_html(sprintf(__('Last sent: %s', 'clockwork-renegade'), $lastSentFormatted)); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="clockwork-card__body">
                    <p style="color:#6b7280;font-size:13px;margin:0 0 16px;">
                        <?php esc_html_e('Choose who receives Care Plan maintenance reports (plugin/core updates, uptime, backups, and security scans) for this site.', 'clockwork-renegade'); ?>
                    </p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row" style="width:180px;">
                                <label for="report_enabled"><?php esc_html_e('Report Delivery', 'clockwork-renegade'); ?></label>
                            </th>
                            <td>
                                <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;">
                                    <input type="checkbox"
                                           id="report_enabled"
                                           name="report_enabled"
                                           value="1"
                                           <?php echo $enabled ? 'checked="checked"' : ''; ?>>
                                    <?php esc_html_e('Send scheduled Care Plan maintenance reports for this site', 'clockwork-renegade'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="width:180px;">
                                <label for="report_frequency"><?php esc_html_e('Frequency', 'clockwork-renegade'); ?></label>
                            </th>
                            <td>
                                <select id="report_frequency" name="report_frequency" style="min-width:220px;">
                                    <option value="monthly" <?php echo $frequency === 'monthly' ? 'selected="selected"' : ''; ?>><?php esc_html_e('Monthly (1st of each month)', 'clockwork-renegade'); ?></option>
                                    <option value="weekly" <?php echo $frequency === 'weekly' ? 'selected="selected"' : ''; ?>><?php esc_html_e('Weekly (Mondays)', 'clockwork-renegade'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="width:180px;">
                                <label for="cwk-recipient-input"><?php esc_html_e('Recipients', 'clockwork-renegade'); ?></label>
                            </th>
                            <td>
                                <div id="cwk-recipients-pills" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">
                                    <?php foreach ($recipients as $email) : ?>
                                        <span class="cwk-recipient-pill" data-email="<?php echo esc_attr($email); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:999px;font-size:12px;color:#1e40af;font-weight:500;">
                                            <span><?php echo esc_html($email); ?></span>
                                            <button type="button" class="cwk-remove-recipient" aria-label="<?php echo esc_attr(sprintf(__('Remove %s', 'clockwork-renegade'), $email)); ?>" style="background:none;border:none;color:#3b82f6;cursor:pointer;padding:0 2px;font-size:14px;line-height:1;">&times;</button>
                                        </span>
                                    <?php endforeach; ?>
                                </div>

                                <div style="display:flex;gap:8px;max-width:480px;">
                                    <input type="email"
                                           id="cwk-recipient-input"
                                           name="report_recipient_pending"
                                           placeholder="name@example.com"
                                           class="regular-text"
                                           style="flex:1;margin:0;">
                                    <button type="button" class="button" id="cwk-add-recipient-btn"><?php esc_html_e('+ Add Email', 'clockwork-renegade'); ?></button>
                                </div>

                                <textarea name="report_recipients"
                                          id="cwk-recipients-hidden"
                                          style="display:none;"><?php echo esc_html(implode("\n", $recipients)); ?></textarea>

                                <p class="description" style="margin-top:6px;">
                                    <?php echo wp_kses_post(__('Add one or more email addresses. Press <strong>Enter</strong> or click <strong>+ Add Email</strong>, then click <strong>Save notification settings</strong> below.', 'clockwork-renegade')); ?>
                                </p>

                                <?php if (! empty($adminSuggestions)) : ?>
                                    <div style="margin-top:10px;">
                                        <span style="font-size:12px;color:#6b7280;display:block;margin-bottom:6px;"><?php esc_html_e('Quick-add site administrators:', 'clockwork-renegade'); ?></span>
                                        <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                            <?php foreach ($adminSuggestions as $adminEmail => $adminName) : ?>
                                                <button type="button"
                                                        class="cwk-quick-add-admin"
                                                        data-email="<?php echo esc_attr($adminEmail); ?>"
                                                        style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;background:#f9fafb;border:1px dashed #d1d5db;border-radius:999px;font-size:11px;color:#374151;cursor:pointer;">
                                                    + <?php echo esc_html($adminEmail); ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Slack Card -->
            <div class="clockwork-card" style="max-width:720px;">
                <div class="clockwork-card__head">
                    <h2><?php esc_html_e('Slack', 'clockwork-renegade'); ?></h2>
                </div>
                <div class="clockwork-card__body">
                    <p style="color:#6b7280;font-size:13px;margin:0 0 16px;">
                        <?php
                        echo wp_kses_post(sprintf(
                            /* translators: %s: Slack incoming webhooks documentation URL */
                            __('Enter a Slack <a href="%s" target="_blank" rel="noopener">incoming webhook URL</a> to receive alerts in your Slack workspace when a contact form stops working or this site goes down. Leave blank to disable.', 'clockwork-renegade'),
                            'https://api.slack.com/messaging/webhooks'
                        ));
                        ?>
                    </p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row" style="width:180px;">
                                <label for="slack_webhook_url"><?php esc_html_e('Webhook URL', 'clockwork-renegade'); ?></label>
                            </th>
                            <td>
                                <input type="url"
                                       id="slack_webhook_url"
                                       name="slack_webhook_url"
                                       value="<?php echo esc_attr($webhookUrl); ?>"
                                       placeholder="https://hooks.slack.com/services/…"
                                       class="regular-text"
                                       style="width:100%;max-width:480px;">
                                <p class="description" style="margin-top:6px;">
                                    <?php echo wp_kses_post(__('Must be an <code>https://</code> URL. Generate one at <strong>Your Slack App → Incoming Webhooks → Add New Webhook to Workspace</strong>.', 'clockwork-renegade')); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <?php if ($webhookUrl !== '') : ?>
                        <div style="margin-top:8px;padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:12px;color:#166534;">
                            <?php esc_html_e('✓ A webhook URL is configured. The monitoring app will send alerts to this workspace.', 'clockwork-renegade'); ?>
                        </div>
                    <?php else : ?>
                        <div style="margin-top:8px;padding:10px 14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;color:#6b7280;">
                            <?php
                            printf(
                                /* translators: %s: agency author name */
                                esc_html__('No webhook URL configured. Alerts will only go to %s\'s internal channel.', 'clockwork-renegade'),
                                esc_html(WhiteLabel::getAuthorName())
                            );
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <p style="margin-top:16px;">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save notification settings', 'clockwork-renegade'); ?></button>
            </p>
        </form>

        <script>
        (function() {
            var pillsWrap = document.getElementById('cwk-recipients-pills');
            var input = document.getElementById('cwk-recipient-input');
            var addBtn = document.getElementById('cwk-add-recipient-btn');
            var hidden = document.getElementById('cwk-recipients-hidden');
            if (!pillsWrap || !input || !hidden) return;

            function currentList() {
                var raw = hidden.value.split(/[\s,;]+/);
                var out = [];
                for (var i = 0; i < raw.length; i++) {
                    var e = raw[i].trim().toLowerCase();
                    if (e && out.indexOf(e) === -1) out.push(e);
                }
                return out;
            }

            function renderPills(list) {
                hidden.value = list.join("\n");
                pillsWrap.innerHTML = '';
                list.forEach(function(email) {
                    var pill = document.createElement('span');
                    pill.className = 'cwk-recipient-pill';
                    pill.setAttribute('data-email', email);
                    pill.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:4px 10px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:999px;font-size:12px;color:#1e40af;font-weight:500;';

                    var text = document.createElement('span');
                    text.textContent = email;

                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'cwk-remove-recipient';
                    btn.setAttribute('aria-label', 'Remove ' + email);
                    btn.style.cssText = 'background:none;border:none;color:#3b82f6;cursor:pointer;padding:0 2px;font-size:14px;line-height:1;';
                    btn.innerHTML = '&times;';
                    btn.addEventListener('click', function() {
                        removeEmail(email);
                    });

                    pill.appendChild(text);
                    pill.appendChild(btn);
                    pillsWrap.appendChild(pill);
                });
            }

            function addEmail(raw) {
                var parts = (raw || '').split(/[\s,;]+/);
                var list = currentList();
                var changed = false;
                parts.forEach(function(p) {
                    var email = p.trim().toLowerCase();
                    if (email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) && list.indexOf(email) === -1) {
                        list.push(email);
                        changed = true;
                    }
                });
                if (changed) {
                    renderPills(list);
                }
            }

            function removeEmail(target) {
                var list = currentList().filter(function(e) { return e !== target; });
                renderPills(list);
            }

            if (addBtn) {
                addBtn.addEventListener('click', function() {
                    if (input.value.trim() !== '') {
                        addEmail(input.value);
                        input.value = '';
                        input.focus();
                    }
                });
            }

            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ',') {
                    if (input.value.trim() !== '') {
                        e.preventDefault();
                        addEmail(input.value);
                        input.value = '';
                    }
                }
            });

            var existingRemoves = pillsWrap.querySelectorAll('.cwk-remove-recipient');
            for (var i = 0; i < existingRemoves.length; i++) {
                existingRemoves[i].addEventListener('click', function(ev) {
                    var pill = ev.currentTarget.closest('.cwk-recipient-pill');
                    if (pill) removeEmail(pill.getAttribute('data-email'));
                });
            }

            var quickAdds = document.querySelectorAll('.cwk-quick-add-admin');
            for (var j = 0; j < quickAdds.length; j++) {
                quickAdds[j].addEventListener('click', function(ev) {
                    addEmail(ev.currentTarget.getAttribute('data-email'));
                });
            }
        })();
        </script>
        <?php
    }

    public static function handleSave(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'clockwork-renegade'), 403);
        }

        check_admin_referer('clockwork_save_notifications');

        $rawRecipients = isset($_POST['report_recipients']) ? (string) wp_unslash($_POST['report_recipients']) : '';
        $pending = isset($_POST['report_recipient_pending']) ? (string) wp_unslash($_POST['report_recipient_pending']) : '';
        if (trim($pending) !== '') {
            $rawRecipients .= "\n" . $pending;
        }

        ClientNotifications::save([
            'slack_webhook_url' => isset($_POST['slack_webhook_url']) ? (string) wp_unslash($_POST['slack_webhook_url']) : '',
            'report_recipients' => ClientNotifications::sanitizeRecipients($rawRecipients),
            'report_frequency' => isset($_POST['report_frequency']) ? (string) wp_unslash($_POST['report_frequency']) : 'monthly',
            'report_enabled' => ! empty($_POST['report_enabled']),
        ], 'wp_admin');

        wp_safe_redirect(add_query_arg([
            'page'  => AdvancedPage::SLUG,
            'tab'   => 'notifications',
            'saved' => '1',
        ], admin_url('admin.php')));
        exit;
    }
}
