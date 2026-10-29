<?php

namespace ClockworkRenegade\Admin\Pages;

use ClockworkRenegade\Admin\Layout;
use ClockworkRenegade\WhiteLabel\WhiteLabel;

/**
 * Backups admin page.
 *
 * Reads the cached backups report from wp_options['clockwork_renegade_backups_report']
 * (populated by Clockwork posting to /wp-json/clockwork-renegade/v1/backups-report) and
 * renders it in the Gravity-Forms-style chrome.
 *
 * Backup history is shown for EVERY site, plan or not — hosting tier keeps
 * 30 days, care plan extends to 90. The retention pill in the card header is
 * the canonical place that difference is communicated; the empty-state copy
 * stays plan-agnostic (it's about "no data yet," not "no feature for you").
 *
 * Empty state for a totally-missing report: explicit "agency hasn't pushed yet"
 * notice rather than a scary broken-feature message.
 */
class BackupsPage
{
    public const SLUG = 'clockwork-backups';

    public const OPTION = 'clockwork_renegade_backups_report';

    /**
     * Page size for the history table when on a care plan. Off-plan sites get
     * 30 days of data total — fits on one page, so pagination is hidden there.
     */
    public const PAGE_SIZE = 30;

    public static function render(): void
    {
        Layout::render('backups', [self::class, 'renderBody']);
    }

    /**
     * Compact status for the wp-admin dashboard widget.
     *
     * @return array{active: bool, lastRunLabel: ?string, hasReport: bool}
     */
    public static function summary(): array
    {
        $report = get_option(self::OPTION, null);
        $report = is_array($report) ? $report : null;

        if ($report === null) {
            return ['active' => false, 'lastRunLabel' => null, 'hasReport' => false];
        }

        $config = $report['config'] ?? [];
        $active = (bool) ($config['files'] ?? false) || (bool) ($config['database'] ?? false);
        $history = is_array($report['history'] ?? null) ? $report['history'] : [];
        // Pressable reports carry an explicit last_backup_at instead of a
        // single merged history list (files/database run on independent
        // cadences, reported as two separate lists — see renderHistoryCard()).
        $lastRunAt = $report['last_backup_at'] ?? ($history[0]['date'] ?? null);
        $lastRunLabel = isset($lastRunAt) ? self::formatTimestamp($lastRunAt) : null;

        return ['active' => $active, 'lastRunLabel' => $lastRunLabel, 'hasReport' => true];
    }

    public static function renderBody(): void
    {
        $report = get_option(self::OPTION, null);
        $report = is_array($report) ? $report : null;

        $isAvailableScope = is_array($report) && ($report['history_scope'] ?? 'policy') === 'available';

        Layout::pageHeader(
            __('Backups', 'clockwork-renegade'),
            $isAvailableScope
                ? __('Your host already keeps recent backups of your site — this page shows what\'s currently on record.', 'clockwork-renegade')
                : __('Your site is backed up to off-site storage on a schedule. This page shows the current backup configuration.', 'clockwork-renegade')
        );

        if ($report === null) {
            self::renderEmptyState();
            return;
        }

        self::renderConfigCard($report);
        self::renderOffsiteArchiveCard($report);
        self::renderHistoryCard($report);
    }

    /**
     * Pressable-only, care-plan-only: a second, independent 90-day copy of
     * this site's backups, archived off-host to S3 Glacier by a separate
     * standalone process (not this plugin, not the agency's monitoring app
     * directly — see that project's docs for why). Purely informational
     * plus, when a currently-valid link exists, a direct download of the
     * single most recent archived copy. Omitted entirely for sites not
     * enrolled — no false promise for a site that doesn't have this.
     *
     * @param  array<string, mixed>  $report
     */
    private static function renderOffsiteArchiveCard(array $report): void
    {
        $archive = $report['offsite_archive'] ?? null;
        if (! is_array($archive) || empty($archive['active'])) {
            return;
        }

        $lastArchivedAt = isset($archive['last_archived_at']) ? (string) $archive['last_archived_at'] : null;
        $fsUrl = isset($archive['fs_download_url']) ? (string) $archive['fs_download_url'] : null;
        $dbUrl = isset($archive['db_download_url']) ? (string) $archive['db_download_url'] : null;
        $expiresAt = isset($archive['download_expires_at']) ? strtotime((string) $archive['download_expires_at']) : false;
        // A stale/expired link would just fail with an S3 XML error page if
        // clicked — hide the buttons rather than let that happen. The
        // pushing job refreshes this at least daily, so this should be rare.
        $linksValid = $expiresAt !== false && $expiresAt > time();

        // Two distinct pipelines share this card. Host-copy mode (Pressable):
        // the host's own backups are mirrored off twice a week. Direct mode
        // (source=clockwork-renegade, unhosted/custom sites): this plugin
        // itself takes a full files+database backup on the site's own
        // schedule and streams it straight to Glacier. The copy must not
        // promise the other pipeline's mechanics or cadence.
        $directArchive = in_array((string) ($report['source'] ?? ''), ['clockwork-renegade', 'clockwork-companion'], true);
        ?>
        <div class="clockwork-card" style="margin-bottom: 16px;">
            <div class="clockwork-card__body">
                <div class="clockwork-notice clockwork-notice--ok">
                    <strong><?php esc_html_e('Also archived off-host for 90 days.', 'clockwork-renegade'); ?></strong>
                    <?php if ($directArchive): ?>
                        <?php esc_html_e('A full backup of this site (files and database) is taken on a regular schedule and stored in secure, encrypted cold storage (Amazon S3 Glacier), kept for 90 days — an independent off-site copy in case anything ever happened to the site or its hosting.', 'clockwork-renegade'); ?>
                    <?php else: ?>
                        <?php esc_html_e('Independent of the backups above, your host\'s backups are additionally copied to secure, encrypted cold storage (Amazon S3 Glacier) twice a week, kept for 90 days — a second copy in case anything ever happened to your host account itself.', 'clockwork-renegade'); ?>
                    <?php endif; ?>
                    <?php if ($lastArchivedAt): ?>
                        <div style="margin-top: 6px; font-size: 12px; opacity: 0.8;"><?php
                        echo esc_html(sprintf(
                            /* translators: %s: formatted date/time of the most recent off-site archive */
                            __('Last archived: %s', 'clockwork-renegade'),
                            self::formatTimestamp($lastArchivedAt)
                        ));
                        ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($linksValid && ($fsUrl || $dbUrl)): ?>
                    <div style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap;">
                        <?php if ($fsUrl && $dbUrl): ?>
                            <a href="<?php echo esc_url($fsUrl); ?>" class="button" download><?php esc_html_e('Download latest filesystem backup', 'clockwork-renegade'); ?></a>
                            <a href="<?php echo esc_url($dbUrl); ?>" class="button" download><?php esc_html_e('Download latest database backup', 'clockwork-renegade'); ?></a>
                        <?php else: ?>
                            <a href="<?php echo esc_url($fsUrl ?: $dbUrl); ?>" class="button" download><?php esc_html_e('Download latest backup', 'clockwork-renegade'); ?></a>
                        <?php endif; ?>
                    </div>
                    <div style="margin-top: 6px; font-size: 11px; opacity: 0.65;"><?php esc_html_e('These download links are temporary and refresh automatically — if one doesn\'t work, check back after the next daily refresh.', 'clockwork-renegade'); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function renderEmptyState(): void
    {
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__body">
                <div class="clockwork-notice">
                    <strong><?php esc_html_e('No backup report yet.', 'clockwork-renegade'); ?></strong>
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %1$s: agency/author name */
                        __('%1$s hasn\'t pushed backup configuration to this site yet. This usually means the next scheduled push hasn\'t run — please check back in a few hours, or contact %1$s if this persists.', 'clockwork-renegade'),
                        WhiteLabel::getAuthorName()
                    ));
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private static function renderConfigCard(array $report): void
    {
        $config = $report['config'] ?? [];
        $files = (bool) ($config['files'] ?? false);
        $database = (bool) ($config['database'] ?? false);
        $nextRun = $config['next_run_time'] ?? null;
        $storage = $config['storage_provider'] ?? null;
        $excludePaths = $config['paths_to_exclude'] ?? null;

        // Pressable has no schedule-config API to report a future run time
        // from — but we DO know the most recent backup (the newest history
        // row), which is a real, verifiable fact rather than a guess. Showing
        // "Not scheduled" here read as "nothing is happening," directly
        // contradicting a history table full of hourly backups right below it.
        $availableScope = ($report['history_scope'] ?? 'policy') === 'available';
        $lastBackupAt = $report['last_backup_at'] ?? null;

        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__head">
                <h2><?php esc_html_e('Backup Configuration', 'clockwork-renegade'); ?></h2>
                <?php if ($files || $database) : ?>
                    <span class="clockwork-pill clockwork-pill--ok"><span class="clockwork-pill__dot"></span> <?php esc_html_e('Active', 'clockwork-renegade'); ?></span>
                <?php else : ?>
                    <span class="clockwork-pill clockwork-pill--off"><span class="clockwork-pill__dot"></span> <?php esc_html_e('Disabled', 'clockwork-renegade'); ?></span>
                <?php endif; ?>
            </div>
            <div class="clockwork-card__body">
                <dl class="clockwork-defs">
                    <dt><?php esc_html_e('Files backup', 'clockwork-renegade'); ?></dt>
                    <dd><?php echo $files ? '<span class="clockwork-pill clockwork-pill--ok">'.esc_html__('Enabled', 'clockwork-renegade').'</span>' : '<span class="clockwork-pill clockwork-pill--off">'.esc_html__('Disabled', 'clockwork-renegade').'</span>'; ?></dd>

                    <dt><?php esc_html_e('Database backup', 'clockwork-renegade'); ?></dt>
                    <dd><?php echo $database ? '<span class="clockwork-pill clockwork-pill--ok">'.esc_html__('Enabled', 'clockwork-renegade').'</span>' : '<span class="clockwork-pill clockwork-pill--off">'.esc_html__('Disabled', 'clockwork-renegade').'</span>'; ?></dd>

                    <?php if ($availableScope) : ?>
                        <dt><?php esc_html_e('Last backup', 'clockwork-renegade'); ?></dt>
                        <dd>
                            <?php if ($lastBackupAt) : ?>
                                <?php echo esc_html(self::formatTimestamp($lastBackupAt)); ?>
                            <?php else : ?>
                                <span class="clockwork-pill clockwork-pill--warn"><?php esc_html_e('None recorded yet', 'clockwork-renegade'); ?></span>
                            <?php endif; ?>
                        </dd>
                    <?php else : ?>
                        <dt><?php esc_html_e('Next scheduled run', 'clockwork-renegade'); ?></dt>
                        <dd>
                            <?php if ($nextRun) : ?>
                                <?php echo esc_html(self::formatTimestamp($nextRun)); ?>
                            <?php else : ?>
                                <span class="clockwork-pill clockwork-pill--warn"><?php esc_html_e('Not scheduled', 'clockwork-renegade'); ?></span>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>

                    <?php if (is_array($storage) && (! empty($storage['region']) || ! empty($storage['bucket']))) : ?>
                        <dt><?php esc_html_e('Storage destination', 'clockwork-renegade'); ?></dt>
                        <dd>
                            <?php
                            $bits = [];
                            if (! empty($storage['bucket'])) {
                                $bits[] = (string) $storage['bucket'];
                            }
                            if (! empty($storage['region'])) {
                                $bits[] = (string) $storage['region'];
                            }
                            echo esc_html(implode(' · ', $bits));
                            ?>
                        </dd>
                    <?php endif; ?>

                    <?php if (! empty($excludePaths)) : ?>
                        <dt><?php esc_html_e('Excluded paths', 'clockwork-renegade'); ?></dt>
                        <dd><?php echo esc_html(is_array($excludePaths) ? implode(', ', $excludePaths) : (string) $excludePaths); ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
        <?php
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private static function renderHistoryCard(array $report): void
    {
        $availableScope = ($report['history_scope'] ?? 'policy') === 'available';

        if ($availableScope) {
            // Filesystem and database backups run on independent cadences
            // (daily vs. hourly on Pressable) with their own real sizes —
            // shown as two separate tables rather than forced into paired
            // rows that would misrepresent which component ran when.
            $historyFiles = is_array($report['history_files'] ?? null) ? $report['history_files'] : [];
            $historyDatabase = is_array($report['history_database'] ?? null) ? $report['history_database'] : [];

            self::renderAvailableScopeHistoryTable(__('Filesystem backups', 'clockwork-renegade'), $historyFiles);
            self::renderAvailableScopeHistoryTable(__('Database backups', 'clockwork-renegade'), $historyDatabase);
            self::renderRefreshCaption($report);

            return;
        }

        $history = is_array($report['history'] ?? null) ? $report['history'] : [];
        $totalRuns = count($history);
        $combined = self::historyIsCombinedArchive($report, $history);

        // Care plan determines the retention window the report was filtered to
        // before it was pushed (see Clockwork's PushCompanionBackupsReport).
        // Off-plan sites get 30 days, on-plan sites get 90 days. Default to
        // 30 if the field is missing, matching the off-plan baseline.
        $onCarePlan = ! empty($report['care_plan_enabled']);
        $retentionDays = (int) ($report['retention_days'] ?? 30);

        // Pagination: only relevant when there are more rows than fit on one
        // page. Off-plan sites are bounded at 30 days, so this only kicks in
        // for care-plan sites with >30 history entries.
        $page = isset($_GET['hp']) ? max(1, (int) $_GET['hp']) : 1;
        $totalPages = max(1, (int) ceil($totalRuns / self::PAGE_SIZE));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $pageRows = array_slice($history, $offset, self::PAGE_SIZE);

        $retentionPillVariant = $onCarePlan ? 'ok' : 'warn';
        $retentionPillLabel = sprintf(
            /* translators: %d: number of days of backup history retained */
            __('Last %d days', 'clockwork-renegade'),
            $retentionDays
        );
        ?>
        <div class="clockwork-card">
            <div class="clockwork-card__head">
                <h2><?php esc_html_e('Backup History', 'clockwork-renegade'); ?></h2>
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <span class="clockwork-pill clockwork-pill--<?php echo esc_attr($retentionPillVariant); ?>">
                        <?php echo esc_html($retentionPillLabel); ?>
                    </span>
                    <?php if ($totalRuns > 0) : ?>
                        <span class="clockwork-pill clockwork-pill--info">
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %d: number of backup runs on record */
                                _n('%d run', '%d runs', $totalRuns, 'clockwork-renegade'),
                                $totalRuns
                            ));
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="clockwork-card__body" style="padding-bottom: 0;">
                <?php if ($onCarePlan) : ?>
                    <div class="clockwork-notice clockwork-notice--ok">
                        <?php esc_html_e('Your care plan includes 90 days of backup history.', 'clockwork-renegade'); ?>
                    </div>
                <?php else : ?>
                    <div class="clockwork-notice">
                        <?php esc_html_e('Your hosting plan includes 30 days of backup history.', 'clockwork-renegade'); ?>
                        <button type="button" class="cwk-support-trigger cwk-link-btn"><?php
                        echo esc_html(sprintf(
                            /* translators: %s: agency/author name */
                            __('Talk to %s', 'clockwork-renegade'),
                            WhiteLabel::getAuthorName()
                        ));
                        ?></button> <?php esc_html_e('about a care plan to extend retention to 90 days.', 'clockwork-renegade'); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="clockwork-card__body clockwork-card__body--tight">
                <?php if ($totalRuns === 0) : ?>
                    <div style="padding: 20px;">
                        <div class="clockwork-notice clockwork-notice--muted">
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %1$s: agency/author name */
                                __('Backup history hasn\'t been indexed yet. Your backups are still running on %1$s\'s schedule — this view will populate within 24 hours of %1$s\'s next sync. If you don\'t see runs after that, contact %1$s.', 'clockwork-renegade'),
                                WhiteLabel::getAuthorName()
                            ));
                            ?>
                        </div>
                    </div>
                <?php else : ?>
                    <table class="clockwork-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Date', 'clockwork-renegade'); ?></th>
                                <th><?php esc_html_e('Type', 'clockwork-renegade'); ?></th>
                                <?php if ($combined) : ?>
                                    <th><?php esc_html_e('Size', 'clockwork-renegade'); ?></th>
                                <?php else : ?>
                                    <th><?php esc_html_e('Database', 'clockwork-renegade'); ?></th>
                                    <th><?php esc_html_e('Files', 'clockwork-renegade'); ?></th>
                                    <th><?php esc_html_e('Notes', 'clockwork-renegade'); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pageRows as $row) : ?>
                                <?php if (! is_array($row)) continue; ?>
                                <tr>
                                    <td class="mono"><?php echo esc_html(self::formatTimestamp($row['date'] ?? null) ?: '—'); ?></td>
                                    <td>
                                        <span class="clockwork-pill clockwork-pill--info">
                                            <?php echo esc_html(ucfirst((string) ($row['type'] ?? 'daily'))); ?>
                                        </span>
                                    </td>
                                    <?php if ($combined) : ?>
                                        <td class="mono">
                                            <div><?php echo esc_html(self::formatBytes(self::combinedArchiveBytes($row))); ?></div>
                                            <?php self::renderManifestBreakdown($row['manifest'] ?? null); ?>
                                        </td>
                                    <?php else : ?>
                                        <td class="mono"><?php echo esc_html(self::formatBytes($row['database_bytes'] ?? null)); ?></td>
                                        <td class="mono"><?php echo esc_html(self::formatBytes($row['files_bytes'] ?? null)); ?></td>
                                        <td>
                                            <?php echo esc_html((string) ($row['notes'] ?? '—')); ?>
                                            <?php self::renderManifestBreakdown($row['manifest'] ?? null); ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php self::renderPager($page, $totalPages, $totalRuns); ?>
                <?php endif; ?>
            </div>
        </div>

        <?php self::renderRefreshCaption($report); ?>
        <?php
    }

    /**
     * One of the two Pressable-scope history tables (Filesystem or
     * Database backups) — each entry has a real per-backup size (parsed by
     * Clockwork from Pressable's own title strings), shown newest-first,
     * capped at PAGE_SIZE with a plain "showing N of M" note rather than a
     * full pager (depth here varies a lot by how long the site's existed —
     * not worth building two independent paginators for yet).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function renderAvailableScopeHistoryTable(string $title, array $rows): void
    {
        $total = count($rows);
        $shown = array_slice($rows, 0, self::PAGE_SIZE);
        ?>
        <div class="clockwork-card" style="margin-bottom: 16px;">
            <div class="clockwork-card__head">
                <h2><?php echo esc_html($title); ?></h2>
                <?php if ($total > 0) : ?>
                    <span class="clockwork-pill clockwork-pill--info">
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %d: number of backups on record */
                            _n('%d backup on record', '%d backups on record', $total, 'clockwork-renegade'),
                            $total
                        ));
                        ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="clockwork-card__body clockwork-card__body--tight">
                <?php if ($total === 0) : ?>
                    <div style="padding: 20px;">
                        <div class="clockwork-notice clockwork-notice--muted"><?php esc_html_e('No backups on record yet.', 'clockwork-renegade'); ?></div>
                    </div>
                <?php else : ?>
                    <table class="clockwork-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Date', 'clockwork-renegade'); ?></th>
                                <th><?php esc_html_e('Type', 'clockwork-renegade'); ?></th>
                                <th><?php esc_html_e('Size', 'clockwork-renegade'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shown as $row) : ?>
                                <?php if (! is_array($row)) continue; ?>
                                <tr>
                                    <td class="mono"><?php echo esc_html(self::formatTimestamp($row['date'] ?? null) ?: '—'); ?></td>
                                    <td>
                                        <span class="clockwork-pill clockwork-pill--info">
                                            <?php echo esc_html(ucfirst((string) ($row['type'] ?? 'automatic'))); ?>
                                        </span>
                                    </td>
                                    <td class="mono"><?php echo esc_html(self::formatBytes($row['bytes'] ?? null)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($total > self::PAGE_SIZE) : ?>
                        <div style="padding: 8px 20px 12px; font-size: 12px; color: #6b7280;">
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %1$d: number of rows shown, %2$d: total backups on record */
                                __('Showing the most recent %1$d of %2$d.', 'clockwork-renegade'),
                                self::PAGE_SIZE,
                                (int) $total
                            ));
                            ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private static function renderRefreshCaption(array $report): void
    {
        ?>
        <p class="clockwork-meta-line" style="padding: 0 4px;">
            <?php
            echo esc_html(sprintf(
                /* translators: %s: formatted date/time the report was last refreshed */
                __('Report last refreshed: %s', 'clockwork-renegade'),
                self::formatTimestamp($report['fetched_at'] ?? null) ?: __('unknown', 'clockwork-renegade')
            ));
            ?>
            &middot; <?php
            echo esc_html(sprintf(
                /* translators: %s: name of the system that produced the report */
                __('source: %s', 'clockwork-renegade'),
                (string) ($report['source'] ?? 'agency')
            ));
            ?>
        </p>
        <?php
    }

    /**
     * Render expandable manifest details pill and breakdown.
     *
     * @param  array<string, mixed>|null  $manifest
     */
    private static function renderManifestBreakdown(?array $manifest): void
    {
        if (empty($manifest) || ! is_array($manifest)) {
            return;
        }

        $db = $manifest['database'] ?? [];
        $files = $manifest['files'] ?? [];
        $summary = $manifest['summary'] ?? [];

        $parts = [];
        $tablesCount = (int) ($db['tables_count'] ?? 0);
        if ($tablesCount > 0) {
            $parts[] = sprintf(_n('%d Table', '%d Tables', $tablesCount, 'clockwork-renegade'), $tablesCount);
        }

        $uploadsCount = (int) ($files['uploads']['file_count'] ?? 0);
        if ($uploadsCount > 0) {
            $parts[] = sprintf(_n('%s Media File', '%s Media Files', number_format($uploadsCount), 'clockwork-renegade'), number_format($uploadsCount));
        }

        $pluginsCount = (int) ($files['plugins']['count'] ?? 0);
        if ($pluginsCount > 0) {
            $parts[] = sprintf(_n('%d Plugin', '%d Plugins', $pluginsCount, 'clockwork-renegade'), $pluginsCount);
        }

        $themesCount = (int) ($files['themes']['count'] ?? 0);
        if ($themesCount > 0) {
            $parts[] = sprintf(_n('%d Theme', '%d Themes', $themesCount, 'clockwork-renegade'), $themesCount);
        }

        if (empty($parts)) {
            return;
        }

        $summaryText = implode(' · ', $parts);
        ?>
        <div class="cwk-manifest-details" style="margin-top: 6px; font-size: 11px;">
            <details style="cursor: pointer; background: rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.06); border-radius: 6px; padding: 6px 10px;">
                <summary style="font-weight: 500; color: #475569; outline: none; list-style-position: inside;">
                    <span style="color: #0f172a; font-weight: 600;"><?php esc_html_e('Includes:', 'clockwork-renegade'); ?></span>
                    <span><?php echo esc_html($summaryText); ?></span>
                    <span style="color: var(--color-brand, #2563eb); text-decoration: underline; margin-left: 6px; font-size: 10px;"><?php esc_html_e('Inspect', 'clockwork-renegade'); ?></span>
                </summary>
                <div style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed rgba(0,0,0,0.08); font-size: 11px; line-height: 1.5; color: #334155;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin-bottom: 8px;">
                        <div>
                            <strong style="display: block; color: #0f172a; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em;"><?php esc_html_e('Database', 'clockwork-renegade'); ?></strong>
                            <span><?php echo esc_html(sprintf('%d tables · %s rows', $tablesCount, number_format((int) ($db['total_rows'] ?? 0)))); ?></span>
                            <?php if (! empty($db['compressed_sql_bytes'])) : ?>
                                <span style="color: #64748b;">(<?php echo esc_html(self::formatBytes((int) $db['compressed_sql_bytes'])); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <strong style="display: block; color: #0f172a; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em;"><?php esc_html_e('Media Library', 'clockwork-renegade'); ?></strong>
                            <span><?php echo esc_html(sprintf('%s files', number_format($uploadsCount))); ?></span>
                            <?php if (! empty($files['uploads']['bytes'])) : ?>
                                <span style="color: #64748b;">(<?php echo esc_html(self::formatBytes((int) $files['uploads']['bytes'])); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <strong style="display: block; color: #0f172a; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em;"><?php esc_html_e('Plugins & Themes', 'clockwork-renegade'); ?></strong>
                            <span><?php echo esc_html(sprintf('%d plugins · %d themes', $pluginsCount, $themesCount)); ?></span>
                            <?php if (! empty($files['themes']['active'])) : ?>
                                <span style="color: #64748b;">(active: <?php echo esc_html((string) $files['themes']['active']); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <?php if (! empty($summary['compression_ratio'])) : ?>
                            <div>
                                <strong style="display: block; color: #0f172a; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em;"><?php esc_html_e('Compression', 'clockwork-renegade'); ?></strong>
                                <span><?php echo esc_html((string) $summary['compression_ratio']); ?> savings</span>
                                <?php if (! empty($summary['total_uncompressed_bytes'])) : ?>
                                    <span style="color: #64748b;">(<?php echo esc_html(self::formatBytes((int) $summary['total_uncompressed_bytes'])); ?> raw)</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (! empty($files['plugins']['list']) && is_array($files['plugins']['list'])) : ?>
                        <div style="font-size: 10px; color: #64748b; margin-top: 4px;">
                            <strong><?php esc_html_e('Plugins:', 'clockwork-renegade'); ?></strong>
                            <span><?php echo esc_html(implode(', ', array_slice($files['plugins']['list'], 0, 15))); ?><?php echo count($files['plugins']['list']) > 15 ? '…' : ''; ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </details>
        </div>
        <?php
    }

    private static function renderPager(int $page, int $totalPages, int $totalRuns): void
    {
        if ($totalPages <= 1) {
            return;
        }
        $base = admin_url('admin.php?page='.self::SLUG);
        $first = max(1, ($page - 1) * self::PAGE_SIZE + 1);
        $last = min($totalRuns, $page * self::PAGE_SIZE);
        ?>
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; border-top: 1px solid var(--cwk-border, #e5e7eb); font-size: 12px; color: #6b7280;">
            <span><?php
            echo esc_html(sprintf(
                /* translators: %1$d: first row shown, %2$d: last row shown, %3$d: total backup runs */
                __('Showing %1$d–%2$d of %3$d', 'clockwork-renegade'),
                $first,
                $last,
                $totalRuns
            ));
            ?></span>
            <span style="display: flex; gap: 6px;">
                <?php if ($page > 1) : ?>
                    <a class="button button-small" href="<?php echo esc_url($base.'&hp='.($page - 1)); ?>"><?php esc_html_e('‹ Prev', 'clockwork-renegade'); ?></a>
                <?php else : ?>
                    <span class="button button-small" style="opacity: 0.5; pointer-events: none;"><?php esc_html_e('‹ Prev', 'clockwork-renegade'); ?></span>
                <?php endif; ?>
                <span style="padding: 4px 10px;"><?php
                echo esc_html(sprintf(
                    /* translators: %1$d: current page number, %2$d: total pages */
                    __('Page %1$d of %2$d', 'clockwork-renegade'),
                    $page,
                    $totalPages
                ));
                ?></span>
                <?php if ($page < $totalPages) : ?>
                    <a class="button button-small" href="<?php echo esc_url($base.'&hp='.($page + 1)); ?>"><?php esc_html_e('Next ›', 'clockwork-renegade'); ?></a>
                <?php else : ?>
                    <span class="button button-small" style="opacity: 0.5; pointer-events: none;"><?php esc_html_e('Next ›', 'clockwork-renegade'); ?></span>
                <?php endif; ?>
            </span>
        </div>
        <?php
    }

    /**
     * Companion/Glacier archives are one zip (database dump + files). SpinupWP
     * history still splits database_bytes vs files_bytes. Combined reports
     * use history_scope=combined, type=full, or a single size_bytes field.
     *
     * @param  array<string, mixed>  $report
     * @param  array<int, mixed>  $history
     */
    private static function historyIsCombinedArchive(array $report, array $history): bool
    {
        $scope = (string) ($report['history_scope'] ?? '');
        if (in_array($scope, ['combined', 'full'], true)) {
            return true;
        }
        if (in_array((string) ($report['source'] ?? ''), ['clockwork-renegade', 'clockwork-companion'], true)) {
            return true;
        }
        if ($history === []) {
            return false;
        }

        foreach ($history as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = strtolower((string) ($row['type'] ?? ''));
            $hasSplitDb = array_key_exists('database_bytes', $row) && $row['database_bytes'] !== null;
            $isFull = in_array($type, ['full', 'archive', 'combined'], true)
                || array_key_exists('size_bytes', $row);
            if (! $isFull || $hasSplitDb) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function combinedArchiveBytes(array $row): mixed
    {
        foreach (['size_bytes', 'files_bytes', 'database_bytes'] as $key) {
            if (isset($row[$key]) && is_numeric($row[$key]) && (int) $row[$key] > 0) {
                return $row[$key];
            }
        }

        return null;
    }

    /**
     * Human-friendly byte formatter — KB/MB/GB at 1024-based boundaries
     * with one decimal place. Returns "—" for null/zero.
     */
    private static function formatBytes(mixed $bytes): string
    {
        if ($bytes === null || $bytes === '' || ! is_numeric($bytes) || (int) $bytes <= 0) {
            return '—';
        }
        $bytes = (float) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return number_format($bytes, $bytes >= 100 || $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }

    private static function formatTimestamp(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        // wp_date uses the site's configured timezone — what the client expects.
        return wp_date('M j, Y g:i a', $ts);
    }
}
