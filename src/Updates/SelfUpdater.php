<?php

namespace ClockworkRenegade\Updates;

/**
 * Self-updater for installs that are not distributed through WordPress.org.
 *
 * Hooks WordPress's native update flow (the `Update URI` plugin header routes
 * the check through `update_plugins_github.com`), so the update shows up on
 * Dashboard → Updates and Plugins like any other plugin and is applied by
 * WordPress's own upgrader. Nothing is downloaded or executed by this class;
 * it only tells WordPress where the release package lives.
 *
 * Source of truth is the latest published, non-prerelease GitHub Release. The
 * package URL must be an https://github.com/<repo>/releases/download/ asset,
 * anything else is ignored.
 *
 * Opt out with `define('CLOCKWORK_RENEGADE_DISABLE_SELF_UPDATE', true)` or the
 * `clockwork_renegade_self_update_enabled` filter (the WordPress.org build
 * should not ship with this enabled).
 */
class SelfUpdater
{
    public const REPO = 'Clockwork-Web-Dev-LLC/clockwork-renegade';

    public const PLUGIN_FILE = 'clockwork-renegade/clockwork-renegade.php';

    public const SLUG = 'clockwork-renegade';

    public const CACHE_KEY = 'clockwork_renegade_latest_release';

    public const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    public const FAILURE_TTL = HOUR_IN_SECONDS;

    public static function register(): void
    {
        if (! self::enabled()) {
            return;
        }

        add_filter('update_plugins_github.com', [self::class, 'filterUpdate'], 10, 4);
        add_filter('plugins_api', [self::class, 'filterPluginsApi'], 10, 3);
    }

    public static function enabled(): bool
    {
        if (defined('CLOCKWORK_RENEGADE_DISABLE_SELF_UPDATE') && CLOCKWORK_RENEGADE_DISABLE_SELF_UPDATE) {
            return false;
        }

        return (bool) apply_filters('clockwork_renegade_self_update_enabled', true);
    }

    /**
     * `update_plugins_{$hostname}` callback.
     *
     * @param  mixed  $update  Existing update data (false when none).
     * @param  array<string, mixed>  $pluginData  Plugin header data.
     * @param  string  $pluginFile  Plugin file relative to the plugins dir.
     * @return mixed
     */
    public static function filterUpdate($update, $pluginData, $pluginFile, $locales = [])
    {
        if ($pluginFile !== self::PLUGIN_FILE) {
            return $update;
        }

        $current = defined('CLOCKWORK_RENEGADE_VERSION') ? (string) CLOCKWORK_RENEGADE_VERSION : '0';
        $release = self::latestRelease();
        if ($release === null) {
            return $update;
        }

        $info = self::updateFromRelease($release, $current);

        return $info ?? $update;
    }

    /**
     * Build the update array WordPress expects from a release, or null when the
     * release is not newer or has no trustworthy package.
     *
     * @param  array<string, mixed>  $release  Normalized release (see normalizeRelease()).
     * @return array<string, string>|null
     */
    public static function updateFromRelease(array $release, string $current): ?array
    {
        $version = (string) ($release['version'] ?? '');
        $package = (string) ($release['package'] ?? '');

        if ($version === '' || ! version_compare($version, $current, '>')) {
            return null;
        }
        if (! self::isTrustedPackageUrl($package)) {
            return null;
        }

        return [
            'id' => 'https://github.com/'.self::REPO,
            'slug' => self::SLUG,
            'plugin' => self::PLUGIN_FILE,
            'version' => $version,
            'url' => 'https://github.com/'.self::REPO.'/releases/tag/v'.$version,
            'package' => $package,
        ];
    }

    /**
     * `plugins_api` callback — powers the "View details" modal.
     *
     * @param  mixed  $result
     * @param  string  $action
     * @param  mixed  $args
     * @return mixed
     */
    public static function filterPluginsApi($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ! is_object($args) || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }

        $release = self::latestRelease();
        if ($release === null) {
            return $result;
        }

        $body = (string) ($release['body'] ?? '');

        return (object) [
            'name' => 'Clockwork Renegade',
            'slug' => self::SLUG,
            'version' => (string) $release['version'],
            'author' => '<a href="https://clockworkcontrol.com">Clockwork Web Dev, LLC</a>',
            'homepage' => 'https://github.com/'.self::REPO,
            'download_link' => self::isTrustedPackageUrl((string) $release['package']) ? (string) $release['package'] : '',
            'sections' => [
                'description' => 'Connects your WordPress site to Clockwork Control.',
                'changelog' => $body !== '' ? nl2br(esc_html($body)) : 'See the GitHub release notes.',
            ],
        ];
    }

    /** Only HTTPS release-asset downloads from this plugin's own repository are accepted. */
    public static function isTrustedPackageUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        return ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === 'github.com'
            && isset($parts['path'])
            && str_starts_with($parts['path'], '/'.self::REPO.'/releases/download/')
            && str_ends_with($parts['path'], '.zip')
            && ! isset($parts['user'])
            && ! isset($parts['port']);
    }

    /**
     * Reduce a GitHub "latest release" API payload to what we use.
     *
     * @param  array<string, mixed>  $payload
     * @return array{version: string, package: string, body: string}|null
     */
    public static function normalizeRelease(array $payload): ?array
    {
        if (! empty($payload['draft']) || ! empty($payload['prerelease'])) {
            return null;
        }

        $version = ltrim((string) ($payload['tag_name'] ?? ''), 'vV');
        if ($version === '' || ! preg_match('/^\d+(\.\d+)*([-+][0-9A-Za-z.-]+)?$/', $version)) {
            return null;
        }

        $package = '';
        foreach ((array) ($payload['assets'] ?? []) as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $name = (string) ($asset['name'] ?? '');
            if ($name === 'clockwork-renegade.zip' || $name === 'clockwork-renegade-'.$version.'.zip') {
                $package = (string) ($asset['browser_download_url'] ?? '');
                break;
            }
        }

        return [
            'version' => $version,
            'package' => $package,
            'body' => (string) ($payload['body'] ?? ''),
        ];
    }

    /**
     * @return array{version: string, package: string, body: string}|null
     */
    public static function latestRelease(): ?array
    {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached === [] ? null : $cached;
        }

        $release = self::fetchLatestRelease();
        set_transient(self::CACHE_KEY, $release ?? [], $release === null ? self::FAILURE_TTL : self::CACHE_TTL);

        return $release;
    }

    /**
     * @return array{version: string, package: string, body: string}|null
     */
    private static function fetchLatestRelease(): ?array
    {
        $response = wp_remote_get('https://api.github.com/repos/'.self::REPO.'/releases/latest', [
            'timeout' => 8,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'clockwork-renegade/'.(defined('CLOCKWORK_RENEGADE_VERSION') ? CLOCKWORK_RENEGADE_VERSION : 'dev'),
            ],
        ]);

        if (is_wp_error($response) || (int) ($response['response']['code'] ?? 0) !== 200) {
            return null;
        }

        $payload = json_decode((string) ($response['body'] ?? ''), true);

        return is_array($payload) ? self::normalizeRelease($payload) : null;
    }
}
