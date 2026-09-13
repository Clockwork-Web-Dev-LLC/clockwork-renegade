<?php

namespace ClockworkRenegade\Admin\Pages;

use ClockworkRenegade\ActionLog\Repository;
use ClockworkRenegade\Admin\Actions\RunSecurityScanAction;
use ClockworkRenegade\Admin\Layout;
use ClockworkRenegade\AuthAudit\Repository as AuthAuditRepository;
use ClockworkRenegade\SecurityScans\ChecksumsRunner;
use ClockworkRenegade\WhiteLabel\WhiteLabel;

/**
 * Clockwork → Security admin page.
 *
 * Two-card layout that mirrors the Clockwork dashboard's per-site Security
 * tab — same shape, same fields, same status pills — so a client clicking
 * around their wp-admin sees the same result the agency sees in its ops tool.
 *
 * Card states:
 *   - active            → care plan ON + scan available on this host
 *   - host-unavailable  → care plan ON but the scan can't run here (e.g. core
 *                         checksums on a host without wp-cli or PHP file
 *                         hashing). Greyed; explanation rendered.
 *   - care-plan-off     → care plan OFF, both cards greyed regardless.
 *
 * Care plan flag is read from the latest action_log row's care_plan_enabled —
 * Clockwork sets this on every push, so the admin page picks up changes
 * automatically without a separate config push channel.
 *
 * Run buttons fan out to admin-post.php → RunSecurityScanAction. Synchronous;
 * the action redirects back here with a flash message in the URL.
 */
class SecurityPage
{
    public const SLUG = 'clockwork-security';

    /**
     * Pressable-only signal with no SpinupWP equivalent (native plugin/theme
     * vulnerability alerts) — populated by SecuritySummaryReportRoute, empty/
     * absent on SpinupWP sites. Also carries defensive_mode data (still
     * pushed by clockwork:pressable-security-summary-report), but that's
     * intentionally not rendered here — a status line with no way to act on
     * it read as more confusing than useful. Revisit once there's an actual
     * ops-side trigger for it.
     */
    public const PRESSABLE_SUMMARY_OPTION = 'clockwork_renegade_pressable_security_summary';

    public static function render(): void
    {
        Layout::render('security', [self::class, 'renderBody']);
    }

    /**
     * Compact status for the wp-admin dashboard widget. Walks the same three
     * scan targets the page cards show and reuses cardState()/statusPill()
     * so the badge always agrees with what the Security page would render.
     *
     * @return array{variant: string, label: string}
     */
    public static function summary(): array
    {
        $onCarePlan = Repository::latestCarePlanFlag();
        $checksumsRunnable = ChecksumsRunner::isAvailable();

        $targets = [
            'blacklist' => 'hosting',
            'sitecheck' => 'care-plan',
            'core_checksums' => 'care-plan',
        ];
        $rank = ['muted' => 0, 'green' => 1, 'warn' => 2, 'red' => 3];

        $worstVariant = null;
        $anyScanned = false;

        foreach ($targets as $target => $tier) {
            $available = $target === 'core_checksums' ? $checksumsRunnable : true;
            $state = self::cardState($tier, $onCarePlan, $available);
            if ($state !== 'active') {
                continue;
            }
            $latest = Repository::latestByActionLog($target);
            if ($latest === null) {
                continue;
            }
            $anyScanned = true;
            $details = self::decodeDetails($latest['details'] ?? null);
            $pill = self::statusPill($latest, $details, $state, $target);
            if ($worstVariant === null || ($rank[$pill['variant']] ?? 0) > ($rank[$worstVariant] ?? 0)) {
                $worstVariant = $pill['variant'];
            }
        }

        if (! $anyScanned) {
            return ['variant' => 'muted', 'label' => __('Scanning soon', 'clockwork-renegade')];
        }

        return match ($worstVariant) {
            'red' => ['variant' => 'red', 'label' => __('Issues found', 'clockwork-renegade')],
            'warn' => ['variant' => 'warn', 'label' => __('Needs review', 'clockwork-renegade')],
            default => ['variant' => 'green', 'label' => __('All clear', 'clockwork-renegade')],
        };
    }

    public static function renderBody(): void
    {
        $onCarePlan = Repository::latestCarePlanFlag();
        $latestSitecheck = Repository::latestByActionLog('sitecheck');
        $latestChecksum = Repository::latestByActionLog('core_checksums');
        $latestBlacklist = Repository::latestByActionLog('blacklist');
        $checksumsRunnable = ChecksumsRunner::isAvailable();

        Layout::pageHeader(
            __('Security', 'clockwork-renegade'),
            __('Checks Clockwork runs against this site to flag domain blacklist hits, malware, and tampering with WordPress core files.', 'clockwork-renegade')
        );

        self::renderFlash();
        self::renderCarePlanBanner($onCarePlan);

        $sucuriWafBlocked = self::isSucuriBlockedByWaf($latestSitecheck, 'sitecheck');

        ?>
        <div class="clockwork-security-grid">
            <?php
            // Hosting-tier card: always active regardless of care-plan state.
            // No Run button — the scan is driven by Clockwork on a daily
            // schedule, and Companion has no outbound channel back to it.
            self::renderCard([
                'icon' => 'list',
                'title' => __('Domain blacklists', 'clockwork-renegade'),
                'subtitle' => __('Checks Spamhaus DBL, URLHaus, and Google Safe Browsing for any sign your domain has been flagged.', 'clockwork-renegade'),
                'cadence' => __('Daily — 02:15 UTC', 'clockwork-renegade'),
                'scan_type' => 'blacklist',
                'tier' => 'hosting',
                'state' => self::cardState('hosting', $onCarePlan, true),
                'latest' => $latestBlacklist,
                'runnable' => false,
                'host_unavailable_note' => null,
            ]);
            // Sucuri's external scanner is blocked by Cloudflare WAF on proxied
            // sites (403 on every attempt). Hide the widget entirely when that's
            // the case — the internal scans cover the same ground more reliably.
            if (! $sucuriWafBlocked) {
                self::renderCard([
                    'icon' => 'globe',
                    'title' => __('Sucuri SiteCheck', 'clockwork-renegade'),
                    'subtitle' => __('Remote malware and page-content scan that flags suspicious changes to your homepage.', 'clockwork-renegade'),
                    'cadence' => __('Weekly — Mondays 02:00 UTC', 'clockwork-renegade'),
                    'scan_type' => 'sitecheck',
                    'tier' => 'care-plan',
                    'state' => self::cardState('care-plan', $onCarePlan, true),
                    'latest' => $latestSitecheck,
                    'runnable' => true,
                    'host_unavailable_note' => null,
                ]);
            }
            self::renderCard([
                'icon' => 'shield',
                'title' => __('Core file integrity', 'clockwork-renegade'),
                'subtitle' => __("Verifies every WordPress core file against WordPress.org's published checksums. Catches tampering Sucuri can't see.", 'clockwork-renegade'),
                'cadence' => __('Daily — 02:30 UTC', 'clockwork-renegade'),
                'scan_type' => 'core_checksums',
                'tier' => 'care-plan',
                'state' => self::cardState('care-plan', $onCarePlan, $checksumsRunnable),
                'latest' => $latestChecksum,
                'runnable' => true,
                'host_unavailable_note' => $checksumsRunnable
                    ? null
                    : sprintf(
                        /* translators: %s: agency / author display name */
                        __("This host can't run core checksum verification — wp-cli isn't reachable and the WordPress HTTP API is blocked. Talk to %s.", 'clockwork-renegade'),
                        WhiteLabel::getAuthorName()
                    ),
            ]);
            ?>
        </div>
        <?php

        self::renderPressableSummary();
        self::renderHistorySection();
        self::renderAuthFailuresCard();
        self::renderInlineStyles();
    }

    /**
     * Pressable-only: known plugin/theme vulnerabilities (Pressable's own CVE
     * feed). Renders nothing at all on SpinupWP sites (the option is simply
     * never populated there) or on a Pressable site before the first
     * nightly push has run.
     */
    private static function renderPressableSummary(): void
    {
        $summary = get_option(self::PRESSABLE_SUMMARY_OPTION, null);
        if (! is_array($summary)) {
            return;
        }

        $plugins = is_array($summary['vulnerabilities']['plugins'] ?? null) ? $summary['vulnerabilities']['plugins'] : [];
        $themes = is_array($summary['vulnerabilities']['themes'] ?? null) ? $summary['vulnerabilities']['themes'] : [];
        $totalVulnerable = count($plugins) + count($themes);
        ?>
        <div class="clockwork-card" style="margin-bottom: 20px;">
            <div class="clockwork-card__head">
                <h2><?php esc_html_e('Vulnerability scan', 'clockwork-renegade'); ?></h2>
                <?php if ($totalVulnerable === 0) : ?>
                    <span class="clockwork-pill clockwork-pill--ok"><span class="clockwork-pill__dot"></span> <?php esc_html_e('Clean', 'clockwork-renegade'); ?></span>
                <?php else : ?>
                    <span class="clockwork-pill clockwork-pill--warn"><span class="clockwork-pill__dot"></span> <?php
                    echo esc_html(sprintf(
                        /* translators: %d: number of vulnerable plugins/themes */
                        __('%d flagged', 'clockwork-renegade'),
                        (int) $totalVulnerable
                    ));
                    ?></span>
                <?php endif; ?>
            </div>
            <div class="clockwork-card__body">
                <p style="font-size: 12px; color: #6b7280; margin: 0 0 12px;">
                    <?php esc_html_e("Checked against your host's own vulnerability feed for every installed plugin and theme.", 'clockwork-renegade'); ?>
                </p>
                <?php if ($totalVulnerable === 0) : ?>
                    <div class="clockwork-notice clockwork-notice--ok"><?php esc_html_e('No known vulnerabilities in your installed plugins or themes.', 'clockwork-renegade'); ?></div>
                <?php else : ?>
                    <?php foreach ([['label' => __('Plugin', 'clockwork-renegade'), 'rows' => $plugins], ['label' => __('Theme', 'clockwork-renegade'), 'rows' => $themes]] as $group) : ?>
                        <?php foreach ($group['rows'] as $row) : ?>
                            <?php if (! is_array($row)) continue; ?>
                            <div class="clockwork-notice clockwork-notice--warn" style="margin-bottom: 8px;">
                                <strong><?php echo esc_html($group['label']); ?>: <?php echo esc_html((string) ($row['name'] ?? __('unknown', 'clockwork-renegade'))); ?></strong>
                                (v<?php echo esc_html((string) ($row['version'] ?? '?')); ?>)
                                <?php foreach ((is_array($row['alerts'] ?? null) ? $row['alerts'] : []) as $alert) : ?>
                                    <?php if (! is_array($alert)) continue; ?>
                                    <div style="margin-top: 4px; font-size: 12px;">
                                        <?php echo esc_html((string) ($alert['title'] ?? '')); ?>
                                        <?php if (! empty($alert['fixed_in'])) : ?>
                                            <?php
                                            echo esc_html(sprintf(
                                                /* translators: %s: plugin/theme version the vulnerability is fixed in */
                                                __('— fixed in %s', 'clockwork-renegade'),
                                                (string) $alert['fixed_in']
                                            ));
                                            ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Renders a card on Clockwork → Security showing recent failed
     * HMAC verifications. Operator-facing visibility into probing/brute-force
     * attempts: 30-fail-per-IP-per-min rate limit silently locks attackers out
     * but historically left no trace. This is that trace.
     *
     * Empty state ("No failures recorded") is the GOOD state — render it as a
     * positive signal, not a "missing data" warning.
     */
    private static function renderAuthFailuresCard(): void
    {
        $count24h = AuthAuditRepository::countSince(86400);
        $count7d = AuthAuditRepository::countSince(86400 * 7);
        $top = AuthAuditRepository::topOffenders(86400 * 7, 5);
        $recent = AuthAuditRepository::recent(20);
        $totalSeen = count($recent);

        ?>
        <div class="clockwork-card" style="margin-top: 16px;">
            <div class="clockwork-card__head">
                <h2><?php esc_html_e('Authentication audit', 'clockwork-renegade'); ?></h2>
                <?php if ($count24h > 0) : ?>
                    <span class="clockwork-pill clockwork-pill--warn">
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %d: number of failed authentication attempts */
                            __('%d in last 24h', 'clockwork-renegade'),
                            (int) $count24h
                        ));
                        ?>
                    </span>
                <?php elseif ($count7d > 0) : ?>
                    <span class="clockwork-pill clockwork-pill--info">
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %d: number of failed authentication attempts */
                            __('%d in last 7d', 'clockwork-renegade'),
                            (int) $count7d
                        ));
                        ?>
                    </span>
                <?php else : ?>
                    <span class="clockwork-pill clockwork-pill--ok">
                        <i class="dashicons dashicons-yes"></i> <?php esc_html_e('No failures', 'clockwork-renegade'); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="clockwork-card__body">
                <p style="margin: 0 0 12px; color: #4b5563;">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %s: agency / author display name */
                        __('Failed authentication attempts on the Clockwork REST endpoints. Cryptographic protocol is uncrackable; this catalogues probing, misconfiguration, and stale-secret deployments so %s can investigate when the count spikes.', 'clockwork-renegade'),
                        WhiteLabel::getAuthorName()
                    ));
                    ?>
                </p>

                <?php if ($totalSeen === 0) : ?>
                    <div class="clockwork-notice clockwork-notice--ok">
                        <i class="dashicons dashicons-shield-alt"></i>
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %s: agency / author display name */
                            __('No failed attempts have been recorded. %s is the only signed caller of these endpoints.', 'clockwork-renegade'),
                            WhiteLabel::getAuthorName()
                        ));
                        ?>
                    </div>
                <?php else : ?>
                    <?php if (! empty($top)) : ?>
                        <h3 style="margin: 8px 0 4px; font-size: 13px; color: #111827;"><?php esc_html_e('Top offenders (last 7 days)', 'clockwork-renegade'); ?></h3>
                        <ul style="margin: 0 0 16px; padding-left: 20px; color: #4b5563; font-size: 13px;">
                            <?php foreach ($top as $offender) : ?>
                                <li>
                                    <strong><?php echo esc_html($offender['ip']); ?></strong>
                                    <?php
                                    echo esc_html(sprintf(
                                        /* translators: 1: number of attempts, 2: timestamp of the last attempt */
                                        __('— %1$d attempts, last seen %2$s UTC', 'clockwork-renegade'),
                                        (int) $offender['count'],
                                        (string) $offender['last_failed_at']
                                    ));
                                    ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <h3 style="margin: 8px 0 4px; font-size: 13px; color: #111827;"><?php esc_html_e('Recent attempts', 'clockwork-renegade'); ?></h3>
                    <table class="clockwork-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('When', 'clockwork-renegade'); ?></th>
                                <th><?php esc_html_e('IP', 'clockwork-renegade'); ?></th>
                                <th><?php esc_html_e('Reason', 'clockwork-renegade'); ?></th>
                                <th><?php esc_html_e('Endpoint', 'clockwork-renegade'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent as $row) : ?>
                                <?php if (! is_array($row)) {
                                    continue;
                                } ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($row['failed_at'] ?? '')); ?></td>
                                    <td><span class="clockwork-data"><?php echo esc_html((string) ($row['ip'] ?? '')); ?></span></td>
                                    <td><?php echo esc_html((string) ($row['reason'] ?? '')); ?></td>
                                    <td><span class="clockwork-data" style="font-size: 12px;"><?php echo esc_html((string) ($row['request_method'] ?? '').' '.($row['request_path'] ?? '')); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Unified history table beneath the three cards. Pulls every action_log
     * row of `action_type=security_scan` regardless of which scan ran — one
     * row per scan per day, newest first. Mirrors the Backups page's history
     * table style so the two pages feel like siblings.
     */
    /** Page size for the scan history table — kept in sync with ActivityPage. */
    public const HISTORY_PER_PAGE = 25;

    private static function renderHistorySection(): void
    {
        $total = Repository::countByActionType('security_scan');
        $currentPage = Layout::currentPage();
        $offset = ($currentPage - 1) * self::HISTORY_PER_PAGE;
        $rows = Repository::findByActionTypePaged('security_scan', $offset, self::HISTORY_PER_PAGE);

        ?>
        <div class="clockwork-card" style="margin-top: 16px;">
            <div class="clockwork-card__head">
                <h2><?php esc_html_e('Scan History', 'clockwork-renegade'); ?></h2>
                <?php if ($total > 0) : ?>
                    <span class="clockwork-pill clockwork-pill--info">
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %d: number of recorded scans */
                            _n('%d scan', '%d scans', $total, 'clockwork-renegade'),
                            (int) $total
                        ));
                        ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="clockwork-card__body clockwork-card__body--tight">
                <?php if ($total === 0) : ?>
                    <div style="padding: 20px;">
                        <div class="clockwork-notice clockwork-notice--muted">
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %s: agency / author display name */
                                __("No scans recorded yet. Once %s's daily and weekly checks run, their history will populate here.", 'clockwork-renegade'),
                                WhiteLabel::getAuthorName()
                            ));
                            ?>
                        </div>
                    </div>
                <?php else : ?>
                    <table class="clockwork-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('When', 'clockwork-renegade'); ?></th>
                                <th><?php esc_html_e('Check', 'clockwork-renegade'); ?></th>
                                <th><?php esc_html_e('Status', 'clockwork-renegade'); ?></th>
                                <th><?php esc_html_e('Result', 'clockwork-renegade'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row) : ?>
                                <?php
                                if (! is_array($row)) {
                                    continue;
                                }
                                $details = self::decodeDetails($row['details'] ?? null);
                                $scanTarget = (string) ($row['target'] ?? '');
                                $sucuriBlocked = self::isSucuriBlockedByWaf($row, $scanTarget);
                                $pill = self::historyPill($row, $details, $sucuriBlocked);
                                $summary = (string) ($row['summary'] ?? '');
                                if ($summary === '' && $sucuriBlocked) {
                                    $summary = __('Blocked by site firewall (likely Cloudflare).', 'clockwork-renegade');
                                } elseif ($summary === '') {
                                    $summary = '—';
                                }
                                $findings = isset($details['findings']) && is_array($details['findings']) ? $details['findings'] : [];
                                $transport = isset($details['transport']) ? (string) $details['transport'] : '';
                                $scannedCount = $details['scanned_files_count'] ?? null;
                                $scanAborted = (bool) ($details['scan_aborted'] ?? false);
                                $abortReason = (string) ($details['abort_reason'] ?? '');
                                $expandable = ! empty($findings) || $scanAborted;
                                ?>
                                <tr>
                                    <td class="mono">
                                        <?php echo esc_html(self::humanTimeAgo((string) ($row['ran_at'] ?? ''))); ?>
                                        <div style="font-size: 11px; color: #6b7280;">
                                            <?php echo esc_html(self::formatTimestampUtc((string) ($row['ran_at'] ?? '')) ?: ''); ?>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html(self::scanTargetLabel($scanTarget)); ?></td>
                                    <td>
                                        <span class="clockwork-pill clockwork-pill--<?php echo esc_attr($pill['variant']); ?>">
                                            <?php echo esc_html($pill['label']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($expandable) : ?>
                                            <details class="clockwork-finding-toggle">
                                                <summary><?php echo esc_html($summary); ?></summary>
                                                <div class="clockwork-finding-detail">
                                                    <?php if ($transport !== '' || $scannedCount !== null || $scanAborted) : ?>
                                                        <div class="clockwork-finding-meta">
                                                            <?php if ($transport !== '') : ?>
                                                                <span><strong><?php esc_html_e('Transport:', 'clockwork-renegade'); ?></strong> <span class="mono"><?php echo esc_html($transport); ?></span></span>
                                                            <?php endif; ?>
                                                            <?php if ($scannedCount !== null) : ?>
                                                                <span><strong><?php esc_html_e('Files scanned:', 'clockwork-renegade'); ?></strong> <span class="mono"><?php echo esc_html(number_format((int) $scannedCount)); ?></span></span>
                                                            <?php endif; ?>
                                                            <?php if ($scanAborted) : ?>
                                                                <span class="clockwork-finding-meta__warn">
                                                                    <?php esc_html_e('Scan aborted', 'clockwork-renegade'); ?><?php echo $abortReason !== '' ? ': ' . esc_html($abortReason) : ''; ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (! empty($findings)) : ?>
                                                        <div class="clockwork-finding-count">
                                                            <?php
                                                            echo esc_html(sprintf(
                                                                /* translators: %d: number of scan findings */
                                                                _n('%d finding', '%d findings', count($findings), 'clockwork-renegade'),
                                                                (int) count($findings)
                                                            ));
                                                            ?>
                                                        </div>
                                                        <ul class="clockwork-finding-list">
                                                            <?php foreach ($findings as $f) : ?>
                                                                <?php
                                                                if (! is_array($f)) {
                                                                    continue;
                                                                }
                                                                $kind = (string) ($f['kind'] ?? __('unknown', 'clockwork-renegade'));
                                                                $path = (string) ($f['path'] ?? __('(no path)', 'clockwork-renegade'));
                                                                $evidence = (string) ($f['evidence'] ?? '');
                                                                ?>
                                                                <li>
                                                                    <div class="clockwork-finding-list__head">
                                                                        <span class="clockwork-finding-kind"><?php echo esc_html($kind); ?></span>
                                                                        <span class="mono"><?php echo esc_html($path); ?></span>
                                                                    </div>
                                                                    <?php if ($evidence !== '') : ?>
                                                                        <div class="clockwork-finding-evidence mono"><?php echo esc_html($evidence); ?></div>
                                                                    <?php endif; ?>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </div>
                                            </details>
                                        <?php else : ?>
                                            <?php echo esc_html($summary); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
        Layout::renderPagination(
            $total,
            self::HISTORY_PER_PAGE,
            $currentPage,
            ['page' => self::SLUG],
        );
    }

    private static function scanTargetLabel(string $target): string
    {
        return match ($target) {
            'sitecheck' => __('Sucuri SiteCheck', 'clockwork-renegade'),
            'core_checksums' => __('Core integrity', 'clockwork-renegade'),
            'blacklist' => __('Domain blacklists', 'clockwork-renegade'),
            '' => '—',
            default => ucfirst(str_replace('_', ' ', $target)),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $details
     * @return array{variant: string, label: string}
     */
    private static function historyPill(array $row, array $details, bool $sucuriBlocked): array
    {
        if ($sucuriBlocked) {
            return ['variant' => 'muted', 'label' => __('Blocked by firewall', 'clockwork-renegade')];
        }
        $status = (string) ($details['status'] ?? '');
        if ($status === 'failed' || empty($row['ok'])) {
            return ['variant' => 'warn', 'label' => __('Failed', 'clockwork-renegade')];
        }
        // Warning-status scans (e.g. `wp_config_recently_modified` finding
        // with no actual malware) must downgrade to yellow even when
        // modified_files_count is non-zero. The agency-side scanner stamps
        // status='warning' on these; render them as Review, not Issues.
        if ($status === 'warning') {
            return ['variant' => 'warn', 'label' => __('Review', 'clockwork-renegade')];
        }
        $hasIssue = ($status === 'issues_found')
            || ! empty($details['has_malware_hit'])
            || ! empty($details['blacklist_hit'])
            || (int) ($details['modified_files_count'] ?? 0) > 0;
        if ($hasIssue) {
            return ['variant' => 'red', 'label' => __('Issues found', 'clockwork-renegade')];
        }

        return ['variant' => 'green', 'label' => __('Clean', 'clockwork-renegade')];
    }

    private static function formatTimestampUtc(string $ranAtUtc): string
    {
        if ($ranAtUtc === '') {
            return '';
        }
        $ts = strtotime($ranAtUtc.' UTC');
        if (! $ts) {
            return '';
        }

        return wp_date('M j, Y g:i a', $ts);
    }

    /**
     * Hosting-tier cards are always active (subject to host availability) —
     * the check is a hosting feature, not a care-plan deliverable. Care-plan
     * cards collapse to 'care-plan-off' when the flag is false.
     *
     * @return 'active'|'host-unavailable'|'care-plan-off'
     */
    private static function cardState(string $tier, bool $onCarePlan, bool $availableOnHost): string
    {
        if ($tier === 'hosting') {
            return $availableOnHost ? 'active' : 'host-unavailable';
        }
        if (! $onCarePlan) {
            return 'care-plan-off';
        }
        if (! $availableOnHost) {
            return 'host-unavailable';
        }

        return 'active';
    }

    private static function renderFlash(): void
    {
        $msg = isset($_GET['flash']) ? (string) $_GET['flash'] : '';
        if ($msg === '') {
            return;
        }
        ?>
        <div class="clockwork-card" style="border-left: 4px solid #65a30d;">
            <div class="clockwork-card__body">
                <strong>✓</strong> <?php echo esc_html($msg); ?>
            </div>
        </div>
        <?php
    }

    private static function renderCarePlanBanner(bool $onCarePlan): void
    {
        if ($onCarePlan) {
            ?>
            <div class="clockwork-card" style="border-left: 4px solid #65a30d;">
                <div class="clockwork-card__body">
                    <strong><?php esc_html_e('Hosting + care-plan security checks are running.', 'clockwork-renegade'); ?></strong>
                    <?php esc_html_e('Daily domain blacklist checks come with your hosting. Weekly malware scans (Sucuri) and daily WordPress core file integrity verification come with your care plan. Use the buttons below to run one now.', 'clockwork-renegade'); ?>
                </div>
            </div>
            <?php
        } else {
            ?>
            <div class="clockwork-card" style="border-left: 4px solid #f59e0b;">
                <div class="clockwork-card__body">
                    <strong><?php esc_html_e('Hosting includes daily blacklist checks. A care plan adds the deeper scans.', 'clockwork-renegade'); ?></strong>
                    <?php
                    echo wp_kses(
                        sprintf(
                            /* translators: %1$s: agency / author display name (used twice) */
                            __("The <strong>domain blacklist check</strong> is already running — %1\$s checks every day to make sure your domain isn't flagged on Spamhaus, URLHaus, or Google Safe Browsing. With a care plan, they also run <strong>weekly Sucuri malware/JavaScript-injection scans</strong> and <strong>daily WordPress core file integrity verification</strong> over SSH. Talk to %1\$s about adding a care plan.", 'clockwork-renegade'),
                            esc_html(WhiteLabel::getAuthorName())
                        ),
                        ['strong' => []]
                    );
                    ?>
                </div>
            </div>
            <?php
        }
    }

    /**
     * @param  array{
     *   icon: string,
     *   title: string,
     *   subtitle: string,
     *   cadence: string,
     *   scan_type: string,
     *   tier: string,
     *   state: string,
     *   latest: ?array<string, mixed>,
     *   runnable: bool,
     *   host_unavailable_note: ?string,
     * }  $card
     */
    private static function renderCard(array $card): void
    {
        $disabled = $card['state'] !== 'active';
        // Off-plan cards never expose historical scan data — even if rows
        // exist from a previous care-plan period, hiding them keeps the
        // "you can't see the data unless you're on a care plan" promise
        // visually consistent with the "Disabled" pill.
        $latest = $card['state'] === 'care-plan-off' ? null : $card['latest'];
        $details = self::decodeDetails($latest['details'] ?? null);
        $pill = self::statusPill($latest, $details, $card['state'], (string) $card['scan_type']);
        $sucuriBlocked = self::isSucuriBlockedByWaf($latest, (string) $card['scan_type']);

        ?>
        <div class="clockwork-scan-card <?php echo $disabled ? 'is-disabled' : ''; ?>">
            <div class="clockwork-scan-card__head">
                <div class="clockwork-scan-card__title">
                    <span class="clockwork-scan-card__icon"><?php echo wp_kses_post(self::iconSvg($card['icon'])); ?></span>
                    <h2><?php echo esc_html($card['title']); ?></h2>
                </div>
                <span class="clockwork-pill clockwork-pill--<?php echo esc_attr($pill['variant']); ?>">
                    <?php echo esc_html($pill['label']); ?>
                </span>
            </div>
            <p class="clockwork-scan-card__subtitle">
                <?php echo esc_html($card['subtitle']); ?>
            </p>

            <?php if ($card['state'] === 'active' && ! empty($card['runnable'])) : ?>
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="clockwork-scan-card__run">
                    <input type="hidden" name="action" value="<?php echo esc_attr(RunSecurityScanAction::ACTION_HOOK); ?>">
                    <input type="hidden" name="scan_type" value="<?php echo esc_attr($card['scan_type']); ?>">
                    <?php wp_nonce_field(RunSecurityScanAction::NONCE_ACTION); ?>
                    <button type="submit" class="button clockwork-scan-card__run-btn">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Run scan now', 'clockwork-renegade'); ?>
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($card['state'] === 'host-unavailable' && $card['host_unavailable_note']) : ?>
                <p class="clockwork-scan-card__note">
                    <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                    <?php echo esc_html($card['host_unavailable_note']); ?>
                </p>
            <?php endif; ?>

            <?php if ($card['state'] === 'care-plan-off') : ?>
                <p class="clockwork-scan-card__empty">
                    <strong><?php esc_html_e('Included with a care plan.', 'clockwork-renegade'); ?></strong>
                    <?php if ($card['scan_type'] === 'sitecheck') : ?>
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %s: agency / author display name */
                            __('Sucuri SiteCheck runs every Monday — scans your homepage for malware, JavaScript injections, and defacement. If anything trips, %s gets alerted within minutes.', 'clockwork-renegade'),
                            WhiteLabel::getAuthorName()
                        ));
                        ?>
                    <?php else : ?>
                        <?php esc_html_e('Every WordPress core file is verified against WordPress.org\'s published checksums daily. Catches PHP backdoors, modified core files, and shells dropped into wp-includes — the kind of malware Sucuri\'s public scan can\'t see.', 'clockwork-renegade'); ?>
                    <?php endif; ?>
                </p>
            <?php elseif ($latest === null) : ?>
                <p class="clockwork-scan-card__empty">
                    <?php
                    echo wp_kses(
                        sprintf(
                            /* translators: %s: scan cadence, e.g. "Daily — 02:15 UTC" */
                            __('No scan recorded yet. The next scheduled run is <strong>%s</strong>.', 'clockwork-renegade'),
                            esc_html($card['cadence'])
                        ),
                        ['strong' => []]
                    );
                    ?>
                </p>
            <?php elseif ($sucuriBlocked) : ?>
                <p class="clockwork-scan-card__note">
                    <?php
                    echo wp_kses(
                        __("<strong>Your site's firewall (likely Cloudflare) is blocking Sucuri's external scanner.</strong> This is normal for sites behind a WAF — Sucuri tries to fetch the homepage from their servers and your firewall rejects them as bots. The <strong>Domain blacklists</strong> card catches the same blacklist signal directly, and the <strong>Core file integrity</strong> check runs server-side — that's the deeper malware check anyway.", 'clockwork-renegade'),
                        ['strong' => []]
                    );
                    ?>
                </p>
                <dl class="clockwork-scan-card__stats">
                    <?php
                    self::renderStat(__('Last attempt', 'clockwork-renegade'), self::humanTimeAgo((string) ($latest['ran_at'] ?? '')));
                    self::renderStat(__('Response', 'clockwork-renegade'), (string) ($latest['error'] ?? '—'));
                    ?>
                </dl>
            <?php else : ?>
                <p class="clockwork-scan-card__summary">
                    <?php echo esc_html((string) ($latest['summary'] ?? '')); ?>
                </p>

                <dl class="clockwork-scan-card__stats">
                    <?php
                    self::renderStat(__('Scanned', 'clockwork-renegade'), self::humanTimeAgo((string) ($latest['ran_at'] ?? '')));
                    self::renderStat(
                        __('Took', 'clockwork-renegade'),
                        isset($latest['elapsed_ms'])
                            ? sprintf(
                                /* translators: %s: formatted number of milliseconds */
                                __('%s ms', 'clockwork-renegade'),
                                number_format((int) $latest['elapsed_ms'])
                            )
                            : '—'
                    );

                    if ($card['scan_type'] === 'sitecheck') {
                        self::renderStat(__('Malware', 'clockwork-renegade'), ! empty($details['has_malware_hit']) ? __('YES', 'clockwork-renegade') : __('no', 'clockwork-renegade'));
                        self::renderStat(__('Blacklist', 'clockwork-renegade'), ! empty($details['blacklist_hit']) ? __('YES', 'clockwork-renegade') : __('no', 'clockwork-renegade'));
                    } elseif ($card['scan_type'] === 'blacklist') {
                        self::renderStat(__('Blacklisted', 'clockwork-renegade'), ! empty($details['blacklist_hit']) ? __('YES', 'clockwork-renegade') : __('no', 'clockwork-renegade'));
                        self::renderStat(__('Result', 'clockwork-renegade'), ucfirst((string) ($details['status'] ?? 'unknown')));
                    } else {
                        $modified = (int) ($details['modified_files_count'] ?? 0);
                        $version = (string) ($details['wp_version'] ?? '');
                        self::renderStat(__('Files flagged', 'clockwork-renegade'), (string) $modified);
                        self::renderStat(__('WP version', 'clockwork-renegade'), $version !== '' ? $version : '—');
                    }
                    ?>
                </dl>
                <?php if ($pill['variant'] === 'red') : ?>
                    <div class="clockwork-scan-card__alert-note">
                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        <strong><?php
                        echo esc_html(sprintf(
                            /* translators: %s: agency / author display name */
                            __('%s has been alerted to these findings and is investigating.', 'clockwork-renegade'),
                            WhiteLabel::getAuthorName()
                        ));
                        ?></strong>
                        <?php echo WhiteLabel::supportLink(__('Reach out', 'clockwork-renegade')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside supportLink() ?> <?php esc_html_e("if you have questions or haven't heard back.", 'clockwork-renegade'); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function renderStat(string $label, string $value): void
    {
        ?>
        <div class="clockwork-scan-card__stat">
            <dt><?php echo esc_html($label); ?></dt>
            <dd><?php echo esc_html($value); ?></dd>
        </div>
        <?php
    }

    /**
     * Sucuri's external scanner gets blocked by Cloudflare's WAF on CF-proxied
     * sites, returning a 403. That's a configuration limitation, not a security
     * failure — we don't have access to the site's cloudflare_state on the
     * Companion side, so we infer from the error string. A 403/Forbidden on a
     * sitecheck scan is overwhelmingly a WAF block (CF most often).
     *
     * @param  array<string, mixed>|null  $latest
     */
    private static function isSucuriBlockedByWaf(?array $latest, string $scanType): bool
    {
        if ($latest === null || $scanType !== 'sitecheck') {
            return false;
        }
        if (! empty($latest['ok'])) {
            return false;
        }
        $err = strtolower((string) ($latest['error'] ?? ''));
        if ($err === '') {
            return false;
        }

        return str_contains($err, '403') || str_contains($err, 'forbidden');
    }

    /**
     * @param  array<string, mixed>|null  $latest
     * @param  array<string, mixed>  $details
     * @return array{variant: string, label: string}
     */
    private static function statusPill(?array $latest, array $details, string $cardState, string $scanType = ''): array
    {
        if ($cardState === 'care-plan-off') {
            return ['variant' => 'muted', 'label' => __('Disabled', 'clockwork-renegade')];
        }
        if ($cardState === 'host-unavailable') {
            return ['variant' => 'muted', 'label' => __('Not available', 'clockwork-renegade')];
        }
        if ($latest === null) {
            return ['variant' => 'muted', 'label' => __('Not yet scanned', 'clockwork-renegade')];
        }
        if (self::isSucuriBlockedByWaf($latest, $scanType)) {
            return ['variant' => 'muted', 'label' => __('Blocked by firewall', 'clockwork-renegade')];
        }
        $status = (string) ($details['status'] ?? '');
        if ($status === 'failed' || empty($latest['ok'])) {
            return ['variant' => 'warn', 'label' => __('Failed', 'clockwork-renegade')];
        }
        // Same warning carve-out as historyPill() — see comment there.
        if ($status === 'warning') {
            return ['variant' => 'warn', 'label' => __('Review', 'clockwork-renegade')];
        }
        $hasIssue = ($status === 'issues_found')
            || ! empty($details['has_malware_hit'])
            || ! empty($details['blacklist_hit'])
            || (int) ($details['modified_files_count'] ?? 0) > 0;
        if ($hasIssue) {
            return ['variant' => 'red', 'label' => __('Issues found', 'clockwork-renegade')];
        }

        return ['variant' => 'green', 'label' => __('Clean', 'clockwork-renegade')];
    }

    /**
     * Inline minimal CSS so we don't have to ship a new admin.css release just
     * for two cards. Mirrors the dashboard's visual language as closely as
     * WP-admin's contrasting CSS lets us.
     */
    private static function renderInlineStyles(): void
    {
        ?>
        <style>
        .clockwork-security-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }
        .clockwork-scan-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: opacity 0.15s;
        }
        .clockwork-scan-card.is-disabled {
            opacity: 0.55;
            background: #f9fafb;
        }
        .clockwork-scan-card__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .clockwork-scan-card__title {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .clockwork-scan-card__title h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }
        .clockwork-scan-card__icon {
            display: inline-flex;
            color: #6b7280;
        }
        .clockwork-scan-card__icon svg {
            width: 20px;
            height: 20px;
        }
        .clockwork-scan-card__subtitle {
            color: #6b7280;
            margin: 0;
            font-size: 13px;
        }
        .clockwork-scan-card__run {
            margin: 0;
        }
        .clockwork-scan-card__run-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .clockwork-scan-card__run-btn .dashicons {
            width: 16px;
            height: 16px;
            font-size: 16px;
        }
        .clockwork-scan-card__summary {
            margin: 0;
            color: #111827;
            font-size: 14px;
        }
        .clockwork-scan-card__empty {
            margin: 0;
            color: #6b7280;
            font-size: 13px;
        }
        .clockwork-scan-card__note {
            margin: 0;
            padding: 10px 12px;
            background: #f3f4f6;
            border-radius: 6px;
            font-size: 12px;
            color: #4b5563;
        }
        .clockwork-scan-card__alert-note {
            margin: 0;
            padding: 10px 12px;
            background: #fef3c7;
            border: 1px solid #fde68a;
            border-radius: 6px;
            font-size: 13px;
            color: #92400e;
            display: flex;
            align-items: baseline;
            gap: 6px;
            flex-wrap: wrap;
        }
        .clockwork-scan-card__alert-note .dashicons {
            flex-shrink: 0;
            font-size: 16px;
            width: 16px;
            height: 16px;
        }
        .clockwork-scan-card__alert-note a {
            color: #92400e;
        }
        .clockwork-scan-card__stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin: 0;
        }
        .clockwork-scan-card__stat dt {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
            margin-bottom: 2px;
        }
        .clockwork-scan-card__stat dd {
            margin: 0;
            font-weight: 600;
            color: #111827;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 14px;
        }
        .clockwork-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .clockwork-pill--green { background: #dcfce7; color: #15803d; }
        .clockwork-pill--red { background: #fee2e2; color: #b91c1c; }
        .clockwork-pill--warn { background: #fef3c7; color: #92400e; }
        .clockwork-pill--muted { background: #f3f4f6; color: #6b7280; }
        </style>
        <?php
    }

    /**
     * @param  mixed  $raw
     * @return array<string, mixed>
     */
    private static function decodeDetails($raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }
        if (is_array($raw)) {
            return $raw;
        }

        return [];
    }

    private static function humanTimeAgo(string $ranAtUtc): string
    {
        if ($ranAtUtc === '') {
            return '—';
        }
        $ts = strtotime($ranAtUtc.' UTC');
        if (! $ts) {
            return '—';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return __('just now', 'clockwork-renegade');
        }
        if ($diff < 3600) {
            $mins = (int) round($diff / 60);

            return sprintf(
                /* translators: %d: number of minutes */
                _n('%d minute ago', '%d minutes ago', $mins, 'clockwork-renegade'),
                $mins
            );
        }
        if ($diff < 86400) {
            $hours = (int) round($diff / 3600);

            return sprintf(
                /* translators: %d: number of hours */
                _n('%d hour ago', '%d hours ago', $hours, 'clockwork-renegade'),
                $hours
            );
        }
        $days = (int) round($diff / 86400);

        return sprintf(
            /* translators: %d: number of days */
            _n('%d day ago', '%d days ago', $days, 'clockwork-renegade'),
            $days
        );
    }

    private static function iconSvg(string $name): string
    {
        // Minimal inline SVGs so we don't depend on a font-icon kit. Stroke-based,
        // currentColor — pick up the muted gray from the parent .clockwork-scan-card__icon.
        if ($name === 'globe') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>';
        }
        if ($name === 'shield') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>';
        }
        if ($name === 'list') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>';
        }

        return '';
    }
}
