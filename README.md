# Clockwork Renegade

Clockwork Renegade is the open-source, GPL-2.0-or-later WordPress connector agent for **Clockwork Control**, designed for submission to the WordPress.org Plugin Directory.

It connects a WordPress site to a central Clockwork Control dashboard via HMAC-SHA256 authenticated REST endpoints under `/wp-json/clockwork-renegade/v1/`.

## Architecture & Features

- **Pairing & Authentication**: Cryptographic Connection Key exchange via Tools → Clockwork. Per-site 256-bit shared secrets, HMAC-SHA256 request signing, and 5-minute replay window validation.
- **Telemetry & Monitoring**: Instant health checks, WP-Cron schedules, plugin/theme version inventory, and resource diagnostics.
- **Safe Remote Updates**: Remote triggers for WordPress core's native upgrader pipelines (`Plugin_Upgrader`, `Theme_Upgrader`, `Core_Upgrader`). No arbitrary remote execution.
- **Backups**: On-demand streaming of compressed database and file archives directly to AWS S3 Glacier Instant Retrieval.
- **Clean Lifecycle**: WordPress standard `register_activation_hook` onboarding redirect, and zero-residual-footprint `uninstall.php` cleanup.

## Development & Tests

Run test suite via PHPUnit:

```bash
composer install
composer test
```

## License

GNU General Public License v2.0 or later (`GPL-2.0-or-later`).
Copyright (c) 2026 Clockwork Web Dev, LLC.
