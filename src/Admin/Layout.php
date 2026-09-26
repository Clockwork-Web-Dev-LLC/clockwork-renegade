<?php

namespace ClockworkRenegade\Admin;

use ClockworkRenegade\WhiteLabel\WhiteLabel;

/**
 * Renders the Gravity-Forms-style admin chrome wrapping every Clockwork page.
 *
 * Structure:
 *   <div class="clockwork-admin">
 *     <header class="clockwork-admin__header">  ← dark band, logo, version
 *     <nav class="clockwork-admin__tabs">       ← sub-page nav
 *     <div class="clockwork-admin__body">       ← caller-provided content
 */
class Layout
{
    /** @return array<int, array{slug: string, label: string, page?: string}> */
    public static function tabs(): array
    {
        $all = [
            ['slug' => 'activity', 'label' => __('Activity', 'clockwork-renegade'), 'page' => Menu::SLUG],
            ['slug' => 'uptime', 'label' => __('Uptime', 'clockwork-renegade'), 'page' => \ClockworkRenegade\Admin\Pages\UptimePage::SLUG],
            ['slug' => 'security', 'label' => __('Security', 'clockwork-renegade'), 'page' => \ClockworkRenegade\Admin\Pages\SecurityPage::SLUG],
            ['slug' => 'two-factor', 'label' => __('2FA', 'clockwork-renegade'), 'page' => \ClockworkRenegade\Admin\Pages\TwoFactorPage::SLUG],
            ['slug' => 'performance', 'label' => __('Performance', 'clockwork-renegade'), 'page' => \ClockworkRenegade\Admin\Pages\PerformancePage::SLUG],
        ];

        if (\ClockworkRenegade\Admin\Pages\TrafficPage::isSupported()) {
            $all[] = ['slug' => 'traffic', 'label' => __('Traffic', 'clockwork-renegade'), 'page' => \ClockworkRenegade\Admin\Pages\TrafficPage::SLUG];
        }

        $all[] = ['slug' => 'forms', 'label' => __('Forms', 'clockwork-renegade'), 'page' => \ClockworkRenegade\Admin\Pages\FormsPage::SLUG];
        $all[] = ['slug' => 'backups', 'label' => __('Backups', 'clockwork-renegade'), 'page' => \ClockworkRenegade\Admin\Pages\BackupsPage::SLUG];
        $all[] = ['slug' => 'advanced', 'label' => __('Advanced', 'clockwork-renegade'), 'page' => \ClockworkRenegade\Admin\Pages\AdvancedPage::SLUG];

        return $all;
    }

    /**
     * Render the chrome and invoke the body callback inside it.
     *
     * @param  callable  $body   Echoes the page-specific HTML.
     */
    public static function render(string $activeSlug, callable $body): void
    {
        $logoUrl = WhiteLabel::getLogoUrl();
        $brandText = WhiteLabel::getBrandText();
        $pluginName = WhiteLabel::getPluginName();
        $showVersion = WhiteLabel::isVersionVisible();
        $supportLabel = WhiteLabel::getSupportButtonLabel();
        $supportUrl = WhiteLabel::getSupportUrl();
        ?>
        <div class="wrap clockwork-admin">
            <header class="clockwork-admin__header">
                <div class="clockwork-admin__brand">
                    <img src="<?php echo esc_url($logoUrl); ?>" alt="<?php echo esc_attr($pluginName); ?>" />
                    <span class="clockwork-admin__brand-text"><?php echo esc_html($brandText); ?></span>
                </div>
                <div style="display:flex;align-items:center;gap:12px;">
                    <?php if (! WhiteLabel::areHelpLinksHidden()) : ?>
                        <?php if (!empty($supportUrl)) : ?>
                            <a href="<?php echo esc_url($supportUrl); ?>" target="_blank" rel="noopener noreferrer" class="cwk-header-support-btn" style="text-decoration:none;display:inline-flex;align-items:center;">
                                <?php echo esc_html($supportLabel); ?>
                            </a>
                        <?php else : ?>
                            <button type="button" class="cwk-support-trigger cwk-header-support-btn"><?php echo esc_html($supportLabel); ?></button>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($showVersion) : ?>
                        <span class="clockwork-admin__version">v<?php echo esc_html(CLOCKWORK_RENEGADE_VERSION); ?></span>
                    <?php endif; ?>
                </div>
            </header>

            <nav class="clockwork-admin__tabs">
                <?php foreach (self::tabs() as $tab) : ?>
                    <?php $url = admin_url('admin.php?page=' . ($tab['page'] ?? Menu::SLUG)); ?>
                    <a class="clockwork-admin__tab <?php echo $tab['slug'] === $activeSlug ? 'is-active' : ''; ?>"
                       href="<?php echo esc_url($url); ?>">
                        <?php echo esc_html($tab['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="clockwork-admin__body">
                <?php $body(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Helper for the "page header" block (h1 + subhead) inside a body callback.
     */
    /**
     * Render secondary pill subtabs.
     *
     * @param array<int, array{slug: string, label: string}> $subtabs
     * @param string $activeSubtab
     * @param string $pageSlug
     */
    public static function renderSubtabs(array $subtabs, string $activeSubtab, string $pageSlug): void
    {
        if (empty($subtabs)) {
            return;
        }
        ?>
        <nav class="clockwork-admin__subtabs" aria-label="<?php esc_attr_e('Advanced Navigation', 'clockwork-renegade'); ?>">
            <?php foreach ($subtabs as $st) : ?>
                <?php
                $url = add_query_arg([
                    'page' => $pageSlug,
                    'tab'  => $st['slug'],
                ], admin_url('admin.php'));
                $isActive = $st['slug'] === $activeSubtab;
                ?>
                <a class="clockwork-admin__subtab <?php echo $isActive ? 'is-active' : ''; ?>"
                   href="<?php echo esc_url($url); ?>">
                    <?php echo esc_html($st['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    public static function pageHeader(string $title, string $subhead = ''): void
    {
        ?>
        <div class="clockwork-admin__page-header">
            <h1><?php echo esc_html($title); ?></h1>
            <?php if ($subhead !== '') : ?>
                <p><?php echo esc_html($subhead); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a uniform prev/next pagination strip — used by every admin page
     * that renders a list of rows so the UX is consistent between
     * Activity, Security, Forms, Backups, etc.
     *
     * @param  array<string, scalar>  $baseQuery
     */
    public static function renderPagination(int $totalRows, int $perPage, int $currentPage, array $baseQuery): void
    {
        if ($totalRows <= 0 || $perPage <= 0) {
            return;
        }
        $totalPages = (int) ceil($totalRows / $perPage);
        if ($totalPages <= 1) {
            return;
        }
        $currentPage = max(1, min($currentPage, $totalPages));
        $startRow = (($currentPage - 1) * $perPage) + 1;
        $endRow = min($currentPage * $perPage, $totalRows);

        $linkFor = function (int $page) use ($baseQuery): string {
            $q = $baseQuery;
            $q['paged'] = $page;
            return esc_url(add_query_arg($q, admin_url('admin.php')));
        };
        ?>
        <div class="clockwork-pagination">
            <span class="clockwork-pagination__count">
                <?php printf(
                    /* translators: 1: start row, 2: end row, 3: total row count */
                    esc_html__('%1$s – %2$s of %3$s', 'clockwork-renegade'),
                    esc_html(number_format_i18n($startRow)),
                    esc_html(number_format_i18n($endRow)),
                    esc_html(number_format_i18n($totalRows))
                ); ?>
            </span>
            <span class="clockwork-pagination__nav">
                <?php if ($currentPage > 1) : ?>
                    <a class="button" href="<?php echo esc_url($linkFor(1)); ?>" aria-label="<?php esc_attr_e('First page', 'clockwork-renegade'); ?>">«</a>
                    <a class="button" href="<?php echo esc_url($linkFor($currentPage - 1)); ?>" aria-label="<?php esc_attr_e('Previous page', 'clockwork-renegade'); ?>"><?php esc_html_e('‹ Prev', 'clockwork-renegade'); ?></a>
                <?php else : ?>
                    <span class="button disabled" aria-disabled="true">«</span>
                    <span class="button disabled" aria-disabled="true"><?php esc_html_e('‹ Prev', 'clockwork-renegade'); ?></span>
                <?php endif; ?>

                <span class="clockwork-pagination__page">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: current page number, 2: total pages */
                        __('Page %1$s of %2$s', 'clockwork-renegade'),
                        (string) $currentPage,
                        (string) $totalPages
                    ));
                    ?>
                </span>

                <?php if ($currentPage < $totalPages) : ?>
                    <a class="button" href="<?php echo esc_url($linkFor($currentPage + 1)); ?>" aria-label="<?php esc_attr_e('Next page', 'clockwork-renegade'); ?>"><?php esc_html_e('Next ›', 'clockwork-renegade'); ?></a>
                    <a class="button" href="<?php echo esc_url($linkFor($totalPages)); ?>" aria-label="<?php esc_attr_e('Last page', 'clockwork-renegade'); ?>">»</a>
                <?php else : ?>
                    <span class="button disabled" aria-disabled="true"><?php esc_html_e('Next ›', 'clockwork-renegade'); ?></span>
                    <span class="button disabled" aria-disabled="true">»</span>
                <?php endif; ?>
            </span>
        </div>
        <style>
            .clockwork-pagination {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
                flex-wrap: wrap;
                margin-top: 12px;
                font-size: 12px;
                color: #6b7280;
            }
            .clockwork-pagination__nav {
                display: inline-flex;
                gap: 6px;
                align-items: center;
            }
            .clockwork-pagination__nav .button {
                display: inline-flex;
                align-items: center;
                padding: 0 10px;
                font-size: 12px;
                height: 26px;
                min-height: 0;
                line-height: 1;
                box-sizing: border-box;
            }
            .clockwork-pagination__nav .button.disabled {
                opacity: 0.4;
                pointer-events: none;
            }
            .clockwork-pagination__page {
                padding: 0 8px;
                color: #374151;
            }
        </style>
        <?php
    }

    /**
     * Resolve and clamp the `paged` query arg. Centralised so every admin
     * page parses it identically.
     */
    public static function currentPage(): int
    {
        $raw = isset($_GET['paged']) ? (int) $_GET['paged'] : 1;
        return max(1, $raw);
    }
}
