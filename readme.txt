=== Clockwork Renegade ===
Contributors: aaronreimann, clockworkwebdev
Tags: monitoring, backups, updates, security, fleet
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Clockwork Renegade connects your WordPress site to Clockwork Control for centralized monitoring, uptime verification, updates, and telemetry.

== Description ==

Clockwork Renegade is the open-source WordPress connector agent for **Clockwork Control**, the centralized site and server management platform designed for WordPress agencies, freelancers, and site owners.

Clockwork Renegade connects to a 3rd party external service, Clockwork Control (operated by Clockwork Web Dev, LLC), via secure HMAC-SHA256 authenticated REST endpoints.

= Features =

* **Fleet Telemetry**: Surface plugin, theme, and WordPress core version statuses to your central dashboard.
* **Automated Upgrades**: Receive signed, remote triggers to run WordPress core's native upgrader functions for core, plugins, and themes.
* **Health & Uptime Monitoring**: Instant verification and diagnostics when paired with Clockwork Control.
* **On-Demand Backups**: Stream compressed database and file snapshots securely directly to off-site AWS S3 Glacier storage.
* **Security & Performance Telemetry**: Local malware detection heuristics, Wordfence/LLAR block logs, and Core Web Vitals performance reporting.
* **White Labeling**: Customize branding colors, menu labels, and agency contact details.

= 3rd Party Service & Privacy Disclosures =

This plugin acts as a client interface to an external service:

* **Service Name**: Clockwork Control
* **Service Provider**: Clockwork Web Dev, LLC
* **Service URL**: [clockworkcontrol.com](https://clockworkcontrol.com)
* **Terms of Service**: [https://clockworkcontrol.com/terms](https://clockworkcontrol.com/terms)
* **Privacy Policy**: [https://clockworkcontrol.com/privacy](https://clockworkcontrol.com/privacy)

**Affirmative Consent & Data Transmission**:
No communication occurs between Clockwork Renegade and Clockwork Control until an authorized administrator affirmatively connects the site. To connect, the site administrator copies the unique cryptographic Connection Key generated under **Clockwork → Connection** and pastes it into their Clockwork Control instance.

When paired, the following data is transmitted to your configured Clockwork Control dashboard via HMAC-authenticated HTTPS requests:
1. Site domain, PHP version, WordPress version, and web server software.
2. Active and inactive plugin and theme lists with current version numbers.
3. WordPress administrator and editor accounts (usernames, display names, and email addresses) used for fleet admin audit and one-click SSO login.
4. WP-Cron task execution schedules and overdue status.
5. Database table sizes and media disk usage metrics.
6. Uptime probe receipts and optional contact-form test verification.
7. Local malware-scan findings (file paths and signature matches). Wordfence / Limit Login Attempts Reloaded lockout summaries when those plugins are installed.
8. Optional one-click SSO: Clockwork Control may request a single-use magic login URL for a named administrator. That URL is consumed locally and is not reused.

= Amazon Web Services (S3 / Glacier) =

Off-site backup and restore stream your WordPress **database dump and wp-content files** to an Amazon S3 bucket that your Clockwork Control operator configures (typically Glacier Instant Retrieval). Clockwork Renegade never stores AWS access keys. Control mints a short-lived presigned HTTPS URL; this plugin uploads or downloads only that object.

* **Service**: Amazon Simple Storage Service (S3)
* **Provider**: Amazon Web Services, Inc.
* **Service URL**: https://aws.amazon.com/s3/
* **Terms**: https://aws.amazon.com/service-terms/
* **Privacy**: https://aws.amazon.com/privacy/

A backup includes whatever is in your database (comments, users, form entries, and other site content). Treat restore as a full-site overwrite of matching tables and files.

= Sucuri SiteCheck =

The Security screen can run an on-demand malware/blacklist scan by sending your site's public home URL to Sucuri's free SiteCheck API. This happens only when an administrator clicks **Run** on that scan. No Clockwork pairing is required for that local button.

* **Service**: Sucuri SiteCheck
* **Provider**: GoDaddy Security / Sucuri
* **Service URL**: https://sitecheck.sucuri.net/
* **Terms**: https://sitecheck.sucuri.net/terms/
* **Privacy**: https://www.sucuri.net/privacy-policy/

== Installation ==

1. Upload the `clockwork-renegade` folder to your `/wp-content/plugins/` directory, or install it directly via the WordPress Plugin Directory (**Plugins → Add New**).
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Clockwork → Connection** (the Clockwork menu in wp-admin).
4. Click **Copy Connection Key**.
5. In your Clockwork Control dashboard, click **+ Add Site**, paste the Connection Key, and confirm.

== Frequently Asked Questions ==

= Does Clockwork Renegade work without Clockwork Control? =
Clockwork Renegade includes a local administration area under **Clockwork → Connection** that provides diagnostic tools and site telemetry. However, connecting to a self-hosted or cloud Clockwork Control dashboard unlocks the full power of centralized updates, uptime alerting, and off-site backup orchestration.

= Where are the Terms of Service? =
Clockwork Control’s Terms of Service are published at https://clockworkcontrol.com/terms. They describe pairing consent, the self-hosted data model, native WordPress upgraders only (no eval), and clean uninstall.

= How is communication secured? =
All communications between Clockwork Control and Clockwork Renegade are encrypted over HTTPS and authenticated using per-site 256-bit HMAC-SHA256 signatures with 5-minute replay-window guards.

= Does this plugin run arbitrary code remotely? =
No. Clockwork Renegade does not execute arbitrary remote code or eval(). All updates and maintenance operations strictly invoke WordPress core's native APIs (`Plugin_Upgrader`, `Theme_Upgrader`, `Core_Upgrader`, etc.).

== Screenshots ==

1. Connection pairing screen with one-click cryptographic key copying.
2. Site activity and operation audit history.
3. System telemetry and resource performance diagnostics.

== Changelog ==

= 1.0.3 - 2026-09-18 =
* Public admin menu visibility for all administrators with manage_options.

= 1.0.2 - 2026-09-15 =
* Security: Lock down White-Label Branding so it is managed exclusively in Clockwork Control. Direct page requests and local form submissions fail closed with 403 Forbidden.
* Fix: Tolerate nullable filter callback inputs in whitelabel, 2FA, and SSO callbacks.

= 1.0.1 - 2026-09-15 =
* Feature: Chunked database optimization endpoints for revisions, transients, and drafts.
* Feature: Remote debug.log viewer with automatic redaction of sensitive credentials.
* Feature: Plugin lifecycle management (activate, deactivate, delete, and WordPress.org install).
* Hardening: Strengthened path traversal checks and payload boundaries.

= 1.0.0 =
* Initial public release for the WordPress.org Plugin Directory.
* Complete ManageWP-style pairing screen with base64 Connection Key.
* HMAC-SHA256 authenticated REST endpoints under `/wp-json/clockwork-renegade/v1/`.
* Safe remote triggers for native WordPress core, plugin, and theme updates.
* S3 Glacier direct-stream backup and restore orchestration.
* White-label branding customization and notification preferences.
* Complete uninstall cleanup of all options, transients, and tables.

== Upgrade Notice ==

= 1.0.0 =
Initial release of Clockwork Renegade for WordPress.org.
