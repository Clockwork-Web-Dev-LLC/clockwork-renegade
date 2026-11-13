# Clockwork Renegade

Clockwork Renegade is the open-source, GPL-2.0-or-later WordPress connector agent for **Clockwork Control**, designed for submission to the WordPress.org Plugin Directory.

It connects a WordPress site to a central Clockwork Control dashboard via HMAC-SHA256 authenticated REST endpoints under `/wp-json/clockwork-renegade/v1/`.

## Architecture & Features

- **Pairing & Authentication**: Cryptographic Connection Key exchange via Clockwork → Connection. Per-site 256-bit shared secrets, HMAC-SHA256 request signing, 5-minute replay window validation, and two-phase secret rotation (the new secret stays pending until Control first uses it).
- **Telemetry & Monitoring**: Instant health checks, WP-Cron schedules, plugin/theme version inventory, and resource diagnostics.
- **Safe Remote Updates**: Remote triggers for WordPress core's native upgrader pipelines (`Plugin_Upgrader`, `Theme_Upgrader`, `Core_Upgrader`). No arbitrary remote execution.
- **Backups**: On-demand streaming of compressed database and file archives directly to AWS S3 Glacier Instant Retrieval. Work files are kept outside the web root when possible (`CLOCKWORK_BACKUP_DIR`).
- **Gatekeeper**: Built-in login-attempt limiter (off until Control enables it; IPv6 counted per /64; network-wide on multisite), managed under Clockwork → Advanced → Gatekeeper. Forwarding headers are trusted only from configured proxies (`CLOCKWORK_RENEGADE_TRUSTED_PROXIES`, or the legacy `CLOCKWORK_RENEGADE_TRUST_PROXY` = Cloudflare + private networks). Rescue switch: `CLOCKWORK_GATEKEEPER_DISABLE`.
- **Two-Factor Authentication**: Optional TOTP with backup codes (Clockwork → Login Security), per-account code lockout, no password-only XML-RPC/REST login for enrolled users, and a current code required to change an active factor. Rescue switch: `CLOCKWORK_2FA_DISABLE`.
- **Maintenance Mode**: 503 page during restores or when Control switches it on; WP-CLI always passes. Active plugin updates run under WordPress core's maintenance mode with a fatal-error check.
- **Support Form**: Optional in-dashboard ticket form that posts to a Gravity Forms form on the agency's site (`CLOCKWORK_SUPPORT_SITE_URL`, `CLOCKWORK_SUPPORT_GF_FORM_ID`, `CLOCKWORK_SUPPORT_GF_FIELD_MAP`, `CLOCKWORK_SUPPORT_GF_KEY` / `CLOCKWORK_SUPPORT_GF_SECRET`, `clockwork_renegade_support_form` filter). Unconfigured by default.
- **Clean Lifecycle**: WordPress standard `register_activation_hook` onboarding redirect, and an `uninstall.php` that removes every table, option, user-meta key, cron event and work file the plugin creates, except data shared with Clockwork Companion while Companion is installed.

All `wp-config.php` constants are listed in the FAQ of [`readme.txt`](readme.txt).

## Development & Tests

Run test suite via PHPUnit:

```bash
composer install
composer test
```

## Changelog

The full changelog is in [`readme.txt`](readme.txt) (`== Changelog ==`).

### Unreleased

The November 2026 audit fixes (Gatekeeper trusted proxies and bypass fix, two-phase secret rotation, 2FA code lockout, working maintenance mode, `%`-safe and verified database dumps, checksum-verified self-update, complete uninstall). See `= Unreleased =` in `readme.txt`.

- **Release step:** `languages/clockwork-renegade.pot` was regenerated on 2026-11-12. Regenerate it again after the version bump so its header shows the new version: `wp i18n make-pot . languages/clockwork-renegade.pot --slug=clockwork-renegade --domain=clockwork-renegade --exclude=vendor,tests,node_modules,bin`.

## Releasing

1. Set the same version in the plugin header (`Version:`), `CLOCKWORK_RENEGADE_VERSION` in `clockwork-renegade.php` and `Stable tag:` in `readme.txt`, and rename `= Unreleased =` in the changelog to that version.
2. Regenerate `languages/clockwork-renegade.pot` with `wp i18n make-pot . languages/clockwork-renegade.pot`.
3. Push a tag `v<version>`. `.github/workflows/release.yml` checks the versions match, runs the test suite, builds `dist/clockwork-renegade.zip` and its `.sha256` with `bin/build-release-zip.sh`, and publishes both as a GitHub Release, which installed copies pick up through the self-updater.

## License

GNU General Public License v2.0 or later (`GPL-2.0-or-later`).
Copyright (c) 2026 Clockwork Web Dev, LLC.
