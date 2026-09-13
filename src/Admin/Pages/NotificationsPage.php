<?php

namespace ClockworkRenegade\Admin\Pages;

use ClockworkRenegade\Admin\Layout;
use ClockworkRenegade\Notifications\ClientNotifications;
use ClockworkRenegade\WhiteLabel\WhiteLabel;

/**
 * Notifications settings page — lets Clockwork configure where client-facing
 * alerts are delivered (currently: a Slack incoming webhook URL).
 *
 * When a Slack webhook URL is saved here, the monitoring app reads it from
 * the /snapshot endpoint and sends plain-English alerts directly to that
 * Slack workspace when things break (form failures, site-down events).
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
        $saved = isset($_GET['saved']) && $_GET['saved'] === '1';
        $nonce = wp_create_nonce('clockwork_save_notifications');
        ?>
        <?php Layout::pageHeader(__('Notifications', 'clockwork-renegade'), __('Configure where alerts are delivered when something breaks on this site.', 'clockwork-renegade')); ?>

        <?php if ($saved) : ?>
            <div class="notice notice-success is-dismissible" style="margin:16px 0;">
                <p><strong><?php esc_html_e('Notification settings saved.', 'clockwork-renegade'); ?></strong></p>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="clockwork_save_notifications">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">

            <div class="clockwork-card" style="max-width:640px;">
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
        <?php
    }

    public static function handleSave(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'clockwork-renegade'), 403);
        }

        check_admin_referer('clockwork_save_notifications');

        ClientNotifications::save([
            'slack_webhook_url' => $_POST['slack_webhook_url'] ?? '',
        ]);

        wp_safe_redirect(add_query_arg([
            'page'  => self::SLUG,
            'saved' => '1',
        ], admin_url('admin.php')));
        exit;
    }
}
