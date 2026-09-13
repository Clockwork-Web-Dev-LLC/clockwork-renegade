<?php

namespace ClockworkRenegade\Admin\Pages;

use ClockworkRenegade\ActionLog\Repository;
use ClockworkRenegade\Admin\Layout;
use ClockworkRenegade\WhiteLabel\WhiteLabel;

/**
 * Clockwork → Performance admin page.
 *
 * Shows the latest Lighthouse score, Core Web Vitals (LCP, CLS, TBT, FCP, SI),
 * and a scan-history table — care-plan-only.
 *
 * Off-plan customers see a greyed page with an upsell explaining what the
 * care plan adds. We deliberately don't surface historical scan rows when the
 * flag is off, even if data exists from a previous care-plan period — keeps
 * the value-of-care-plan promise visually consistent.
 */
class PerformancePage
{
    public const SLUG = 'clockwork-performance';

    public static function render(): void
    {
        Layout::render('performance', [self::class, 'renderBody']);
    }

    /**
     * Compact status for the wp-admin dashboard widget — latest Lighthouse
     * score, reusing the same grading helpers as the full page.
     *
     * @return array{hasScore: bool, score: ?int, grade: string, variant: string}
     */
    public static function summary(): array
    {
        $latest = Repository::latestPerformanceScanFromEngine('gtmetrix');
        if ($latest === null || empty($latest['ok'])) {
            return ['hasScore' => false, 'score' => null, 'grade' => '—', 'variant' => 'muted'];
        }

        $details = self::decodeDetails($latest['details'] ?? null);
        $score = isset($details['performance_score']) ? (int) $details['performance_score'] : null;
        $variant = match (self::scoreColorClass($score)) {
            'green' => 'green',
            'orange' => 'warn',
            'red' => 'red',
            default => 'muted',
        };

        return [
            'hasScore' => $score !== null,
            'score' => $score,
            'grade' => self::letterGradeFor($score),
            'variant' => $variant,
        ];
    }

    public static function renderBody(): void
    {
        // Successful GTmetrix rows only, everywhere on this page. PSI
        // fallback rows (and pre-cutover PSI-era rows) score under throttled
        // mobile emulation — 30-60 on sites that grade 90+ on GTmetrix
        // desktop — and failed rows describe scan-engine hiccups (quota,
        // blocked test agent), which are Clockwork's ops problem, not a
        // client-facing result. Showing either made healthy sites look
        // broken, so clients never see them; the raw rows stay in the
        // monitoring app's database for internal analysis.
        $onCarePlan = Repository::latestCarePlanFlag();
        $latest = Repository::latestPerformanceScanFromEngine('gtmetrix');
        $history = $onCarePlan
            ? array_values(array_filter(
                Repository::findByActionType('performance_scan', 100),
                fn ($row) => is_array($row)
                    && ! empty($row['ok'])
                    && (self::decodeDetails($row['details'] ?? null)['engine'] ?? '') === 'gtmetrix'
            ))
            : [];

        Layout::pageHeader(__('Performance', 'clockwork-renegade'));

        self::renderCarePlanBanner($onCarePlan);
        self::renderLatestHero($onCarePlan, $latest);
        self::renderHistorySection($onCarePlan, $history);
        self::renderInlineStyles();
    }

    private static function renderCarePlanBanner(bool $onCarePlan): void
    {
        if ($onCarePlan) {
            ?>
            <div class="clockwork-card" style="border-left: 4px solid #65a30d;">
                <div class="clockwork-card__body">
                    <strong><?php esc_html_e('Weekly speed checks are part of your care plan.', 'clockwork-renegade'); ?></strong>
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %s: agency/author name */
                        __('%s runs a Lighthouse scan against your homepage every week and flags regressions in your performance score.', 'clockwork-renegade'),
                        WhiteLabel::getAuthorName()
                    ));
                    ?>
                </div>
            </div>
            <?php
        } else {
            ?>
            <div class="clockwork-card" style="border-left: 4px solid #f59e0b;">
                <div class="clockwork-card__body">
                    <strong><?php esc_html_e('Add a care plan to unlock weekly speed checks.', 'clockwork-renegade'); ?></strong>
                    <?php
                    echo wp_kses(sprintf(
                        /* translators: %s: agency/author name */
                        __('With a care plan, %s runs a weekly Lighthouse scan via GTmetrix — the same engine Google uses to evaluate site performance, from a pinned test location with consistent hardware. You\'d see your <strong>Performance score</strong>, <strong>Largest Contentful Paint</strong>, <strong>Cumulative Layout Shift</strong>, and <strong>page weight</strong> trended over time, so a slow regression doesn\'t sneak past you.', 'clockwork-renegade'),
                        esc_html(WhiteLabel::getAuthorName())
                    ), ['strong' => []]);
                    ?>
                    <button type="button" class="cwk-support-trigger cwk-link-btn"><?php
                    echo esc_html(sprintf(
                        /* translators: %s: agency/author name */
                        __('Talk to %s', 'clockwork-renegade'),
                        WhiteLabel::getAuthorName()
                    ));
                    ?></button> <?php esc_html_e('about adding a care plan.', 'clockwork-renegade'); ?>
                </div>
            </div>
            <?php
        }
    }

    /**
     * Renders the single "latest scan" card. One nightly scan runs per site
     * (04:45 UTC, weekly rotation), so there is exactly one current result — the old
     * mobile/desktop card pair dates from the PSI era, when two strategies
     * ran per night, and just showed a stale desktop card after the GTmetrix
     * cutover. Off care-plan? Render nothing — the upsell banner above
     * carries the message and an empty card would just be visual clutter.
     *
     * @param  array<string, mixed>|null  $latest
     */
    private static function renderLatestHero(bool $onCarePlan, ?array $latest): void
    {
        if (! $onCarePlan) {
            return;
        }

        if ($latest === null) {
            ?>
            <div class="clockwork-card" style="margin-top: 16px;">
                <div class="clockwork-card__body">
                    <strong><?php esc_html_e('No scans recorded yet.', 'clockwork-renegade'); ?></strong>
                    <?php esc_html_e('Scans run weekly. Results will populate here within the next few days.', 'clockwork-renegade'); ?>
                </div>
            </div>
            <?php
            return;
        }

        $details = self::decodeDetails($latest['details'] ?? null);
        $score = isset($details['performance_score']) ? (int) $details['performance_score'] : null;
        $grade = self::letterGradeFor($score);
        $scoreClass = self::scoreColorClass($score);
        $ranAt = (string) ($latest['ran_at'] ?? '');
        $ok = ! empty($latest['ok']);

        ?>
        <div class="clockwork-perf-hero" style="margin-top: 16px;">
            <div class="clockwork-perf-hero__head">
                <div>
                    <h2 class="clockwork-perf-hero__title"><?php esc_html_e('Latest Scan', 'clockwork-renegade'); ?></h2>
                    <p class="clockwork-perf-hero__sub">
                        <?php echo esc_html(self::humanTimeAgo($ranAt)); ?>
                        · <?php echo esc_html(self::engineConditionsLabel($details)); ?>
                    </p>
                </div>
                <?php if ($ok && $score !== null) : ?>
                    <div class="clockwork-perf-score clockwork-perf-score--<?php echo esc_attr($scoreClass); ?>">
                        <span class="clockwork-perf-score__grade"><?php echo esc_html($grade); ?></span>
                        <span class="clockwork-perf-score__pct"><?php
                        echo esc_html(sprintf(
                            /* translators: %d: performance score out of 100 */
                            __('%d/100', 'clockwork-renegade'),
                            $score
                        ));
                        ?></span>
                    </div>
                <?php else : ?>
                    <span class="clockwork-pill clockwork-pill--warn"><?php esc_html_e('Failed', 'clockwork-renegade'); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($ok) : ?>
                <dl class="clockwork-perf-vitals">
                    <?php
                    self::renderVital(__('LCP', 'clockwork-renegade'), self::formatMs($details['lcp_ms'] ?? null), __('Largest Contentful Paint', 'clockwork-renegade'));
                    self::renderVital(__('CLS', 'clockwork-renegade'), self::formatCls($details['cls_x1000'] ?? null), __('Cumulative Layout Shift', 'clockwork-renegade'));
                    self::renderVital(__('TBT', 'clockwork-renegade'), self::formatMs($details['tbt_ms'] ?? null), __('Total Blocking Time', 'clockwork-renegade'));
                    self::renderVital(__('FCP', 'clockwork-renegade'), self::formatMs($details['fcp_ms'] ?? null), __('First Contentful Paint', 'clockwork-renegade'));
                    self::renderVital(__('Speed Index', 'clockwork-renegade'), self::formatMs($details['si_ms'] ?? null), '');
                    self::renderVital(__('Page weight', 'clockwork-renegade'), self::formatBytes($details['page_weight_bytes'] ?? null), '');
                    ?>
                </dl>
                <?php if ($scoreClass === 'orange' || $scoreClass === 'red') : ?>
                    <p class="clockwork-perf-hero__improve-note">
                        <?php esc_html_e('There may be opportunities to improve this score.', 'clockwork-renegade'); ?>
                        <?php
                        /* translators: %s: agency/author name */
                        echo WhiteLabel::supportLink(sprintf(__('Talk to %s', 'clockwork-renegade'), WhiteLabel::getAuthorName())); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside supportLink()
                        ?>
                        <?php esc_html_e('about performance optimization for this site.', 'clockwork-renegade'); ?>
                    </p>
                <?php endif; ?>
            <?php elseif (! empty($latest['error'])) : ?>
                <p class="clockwork-perf-hero__error">
                    <strong><?php esc_html_e('Scan failed:', 'clockwork-renegade'); ?></strong> <?php echo esc_html((string) $latest['error']); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function renderHistorySection(bool $onCarePlan, array $rows): void
    {
        $total = count($rows);
        ?>
        <div class="clockwork-card" style="margin-top: 16px;">
            <div class="clockwork-card__head">
                <h2><?php esc_html_e('Scan History', 'clockwork-renegade'); ?></h2>
                <?php if ($total > 0) : ?>
                    <span class="clockwork-pill clockwork-pill--info">
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %d: number of performance scans on record */
                            _n('%d scan', '%d scans', $total, 'clockwork-renegade'),
                            $total
                        ));
                        ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="clockwork-card__body clockwork-card__body--tight">
                <?php if (! $onCarePlan) : ?>
                    <div style="padding: 20px;">
                        <div class="clockwork-notice clockwork-notice--muted">
                            <?php esc_html_e('Performance history is part of the care plan.', 'clockwork-renegade'); ?>
                            <?php
                            /* translators: %s: agency/author name */
                            echo WhiteLabel::supportLink(sprintf(__('Talk to %s', 'clockwork-renegade'), WhiteLabel::getAuthorName())); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside supportLink()
                            ?>
                            <?php esc_html_e('about adding one.', 'clockwork-renegade'); ?>
                        </div>
                    </div>
                <?php elseif ($total === 0) : ?>
                    <div style="padding: 20px;">
                        <div class="clockwork-notice clockwork-notice--muted">
                            <?php esc_html_e('No scans recorded yet. Once the next weekly scan completes, history will populate here.', 'clockwork-renegade'); ?>
                        </div>
                    </div>
                <?php else : ?>
                    <table class="clockwork-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('When', 'clockwork-renegade'); ?></th>
                                <th><?php esc_html_e('Engine', 'clockwork-renegade'); ?></th>
                                <th><?php esc_html_e('Score', 'clockwork-renegade'); ?></th>
                                <th><?php esc_html_e('LCP', 'clockwork-renegade'); ?></th>
                                <th><?php esc_html_e('CLS', 'clockwork-renegade'); ?></th>
                                <th><?php esc_html_e('Page weight', 'clockwork-renegade'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row) : ?>
                                <?php
                                if (! is_array($row)) {
                                    continue;
                                }
                                $details = self::decodeDetails($row['details'] ?? null);
                                $score = isset($details['performance_score']) ? (int) $details['performance_score'] : null;
                                $engineLabel = self::engineShortLabel($details);
                                $ok = ! empty($row['ok']);
                                ?>
                                <tr>
                                    <td class="mono">
                                        <?php echo esc_html(self::humanTimeAgo((string) ($row['ran_at'] ?? ''))); ?>
                                        <div style="font-size: 11px; color: #6b7280;">
                                            <?php echo esc_html(self::formatTimestampUtc((string) ($row['ran_at'] ?? '')) ?: ''); ?>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html($engineLabel); ?></td>
                                    <td>
                                        <?php if ($ok && $score !== null) : ?>
                                            <span class="clockwork-perf-pill clockwork-perf-pill--<?php echo esc_attr(self::scoreColorClass($score)); ?>">
                                                <?php echo (int) $score; ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="clockwork-pill clockwork-pill--warn"><?php esc_html_e('Failed', 'clockwork-renegade'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="mono"><?php echo esc_html(self::formatMs($details['lcp_ms'] ?? null)); ?></td>
                                    <td class="mono"><?php echo esc_html(self::formatCls($details['cls_x1000'] ?? null)); ?></td>
                                    <td class="mono"><?php echo esc_html(self::formatBytes($details['page_weight_bytes'] ?? null)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function renderVital(string $label, string $value, string $hint): void
    {
        ?>
        <div class="clockwork-perf-vital">
            <dt><?php echo esc_html($label); ?></dt>
            <dd><?php echo esc_html($value); ?></dd>
            <?php if ($hint !== '') : ?>
                <dd class="clockwork-perf-vital__hint"><?php echo esc_html($hint); ?></dd>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Human label for the engine + test conditions of a scan, from the
     * action-log details payload. Rows written before the 2026-06-27 GTmetrix
     * cutover have no `engine` key — PSI was the only engine then, so the
     * PSI branch is the correct fallback.
     *
     * @param  array<string, mixed>  $details
     */
    private static function engineConditionsLabel(array $details): string
    {
        if (($details['engine'] ?? '') === 'gtmetrix') {
            return __('GTmetrix (desktop Chrome, pinned location)', 'clockwork-renegade');
        }

        $strategy = (string) ($details['strategy'] ?? 'mobile');

        return $strategy === 'desktop'
            ? __('Google PageSpeed (desktop)', 'clockwork-renegade')
            : __('Google PageSpeed (simulated mobile phone)', 'clockwork-renegade');
    }

    /**
     * Compact engine label for the history table.
     *
     * @param  array<string, mixed>  $details
     */
    private static function engineShortLabel(array $details): string
    {
        if (($details['engine'] ?? '') === 'gtmetrix') {
            return __('GTmetrix', 'clockwork-renegade');
        }

        $strategy = (string) ($details['strategy'] ?? '');

        return $strategy !== ''
            ? sprintf(
                /* translators: %s: scan strategy, e.g. mobile or desktop */
                __('PageSpeed · %s', 'clockwork-renegade'),
                $strategy
            )
            : __('PageSpeed', 'clockwork-renegade');
    }

    private static function letterGradeFor(?int $score): string
    {
        if ($score === null) {
            return '—';
        }

        return match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 50 => 'C',
            $score >= 30 => 'D',
            default => 'E',
        };
    }

    /**
     * Color class for Lighthouse-style threshold:
     *   green ≥ 90, orange 50–89, red < 50.
     */
    private static function scoreColorClass(?int $score): string
    {
        if ($score === null) {
            return 'muted';
        }
        if ($score >= 90) {
            return 'green';
        }
        if ($score >= 50) {
            return 'orange';
        }

        return 'red';
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

    private static function formatMs(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '—';
        }
        $ms = (int) $value;
        if ($ms >= 1000) {
            return number_format($ms / 1000, 2).' s';
        }

        return number_format($ms).' ms';
    }

    private static function formatCls(mixed $clsX1000): string
    {
        if (! is_numeric($clsX1000)) {
            return '—';
        }

        return number_format(((int) $clsX1000) / 1000, 3);
    }

    private static function formatBytes(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '—';
        }
        $bytes = (int) $value;
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1).' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0).' KB';
        }

        return $bytes.' B';
    }

    private static function renderInlineStyles(): void
    {
        ?>
        <style>
        .clockwork-perf-hero {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
        }
        .clockwork-perf-hero__head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }
        .clockwork-perf-hero__title {
            margin: 0 0 2px;
            font-size: 16px;
            font-weight: 600;
            color: #111827;
        }
        .clockwork-perf-hero__sub {
            margin: 0;
            font-size: 13px;
            color: #6b7280;
        }
        .clockwork-perf-hero__error {
            margin: 0;
            padding: 10px 12px;
            background: #fef3c7;
            border-radius: 6px;
            color: #92400e;
            font-size: 13px;
        }
        .clockwork-perf-hero__improve-note {
            margin: 16px 0 0;
            padding: 10px 12px;
            background: #f3f4f6;
            border-radius: 6px;
            color: #4b5563;
            font-size: 13px;
        }
        .clockwork-perf-score {
            display: flex;
            align-items: baseline;
            gap: 6px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .clockwork-perf-score__grade {
            font-size: 36px;
            font-weight: 700;
            line-height: 1;
        }
        .clockwork-perf-score__pct {
            font-size: 13px;
            color: #6b7280;
        }
        .clockwork-perf-score--green .clockwork-perf-score__grade { color: #15803d; }
        .clockwork-perf-score--orange .clockwork-perf-score__grade { color: #c2410c; }
        .clockwork-perf-score--red .clockwork-perf-score__grade { color: #b91c1c; }
        .clockwork-perf-score--muted .clockwork-perf-score__grade { color: #6b7280; }

        .clockwork-perf-vitals {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 14px 18px;
            margin: 0;
        }
        .clockwork-perf-vital dt {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
            margin-bottom: 4px;
        }
        .clockwork-perf-vital dd {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            color: #111827;
        }
        .clockwork-perf-vital__hint {
            font-size: 11px !important;
            font-weight: 400 !important;
            color: #9ca3af !important;
            margin-top: 2px !important;
            font-family: -apple-system, sans-serif !important;
        }

        .clockwork-perf-pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-weight: 700;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 13px;
        }
        .clockwork-perf-pill--green { background: #dcfce7; color: #15803d; }
        .clockwork-perf-pill--orange { background: #ffedd5; color: #c2410c; }
        .clockwork-perf-pill--red { background: #fee2e2; color: #b91c1c; }
        .clockwork-perf-pill--muted { background: #f3f4f6; color: #6b7280; }
        </style>
        <?php
    }
}
