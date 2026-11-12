=== Clockwork Renegade ===
Contributors: aaronreimann, clockworkwebdev
Tags: monitoring, backups, updates, security, fleet
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.4
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
* **Security & Performance Telemetry**: Local malware detection heuristics, WordPress core file verification (including unexpected files in `wp-admin` and `wp-includes`), Wordfence/LLAR block logs, and Core Web Vitals performance reporting.
* **Gatekeeper Login Protection**: A built-in login-attempt limiter. Off until your Clockwork Control operator enables it. It counts failed logins per address (IPv6 per /64), locks an address out after a configurable number of failures, extends repeat lockouts, and shows a configurable lockout page. Agency users manage it under **Clockwork → Advanced → Gatekeeper**: active lockouts, unlocking, the policy, and Trusted Proxies.
* **Two-Factor Authentication**: Optional TOTP (authenticator app) second factor with backup codes, on the **Clockwork → Login Security** page. Enrolment is never forced unless `CLOCKWORK_2FA_FORCE_ENROLLMENT` is defined.
* **Maintenance Mode**: A 503 maintenance page that Clockwork Control switches on during restores or on request. While an active plugin is being updated, WordPress core's own maintenance mode is used, and the site is checked for a fatal error before it comes back.
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
7. Local malware-scan findings (file paths and signature matches). Gatekeeper lockouts, and Wordfence / Limit Login Attempts Reloaded lockout summaries when those plugins are installed (locked IP addresses; private and reserved addresses are never sent, and each list is capped at 500 entries).
8. Two-factor enrolment status of administrator and editor accounts.
9. Optional one-click SSO: Clockwork Control may request a single-use magic login URL for a named administrator. That URL is consumed locally and is not reused.

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

= What is Gatekeeper? =
Gatekeeper is Clockwork Renegade's own login-attempt limiter, a replacement for Limit Login Attempts Reloaded. It is off until your Clockwork Control operator pushes a policy that enables it; enabling it deactivates Limit Login Attempts Reloaded. Failed logins and failed two-factor codes count towards a lockout, and a locked address cannot submit two-factor codes either. On multisite the lockouts and the policy are shared by the whole network. If it ever locks you out, add `define('CLOCKWORK_GATEKEEPER_DISABLE', true);` to `wp-config.php`.

= My site is behind Cloudflare or a reverse proxy. What do I need to set? =
Gatekeeper and the request rate limiter only trust the `CF-Connecting-IP` and `X-Forwarded-For` headers from a trusted proxy. List your proxies under **Clockwork → Advanced → Gatekeeper → Trusted Proxies**, or in `wp-config.php`:

`define('CLOCKWORK_RENEGADE_TRUSTED_PROXIES', 'cloudflare, 203.0.113.10');`

Entries are IP addresses, CIDR ranges, `cloudflare` (Cloudflare's published ranges) or `private` (loopback and private networks). The older `CLOCKWORK_RENEGADE_TRUST_PROXY` constant now means "trust Cloudflare and private networks" when no list is set. If your web server already puts the visitor's address in `REMOTE_ADDR` (nginx `real_ip`, Apache `mod_remoteip`), leave the list empty. Without the right setting, everyone behind the same proxy shares one lockout counter.

= How does two-factor authentication work? =
Users can enrol an authenticator app on **Clockwork → Login Security**. For enrolled users:

* After 10 wrong codes within 15 minutes, that account's code step is locked for 15 minutes.
* A password alone no longer logs in over XML-RPC or the REST API; application passwords still work.
* Turning 2FA off, regenerating backup codes, or replacing the authenticator requires a current code.

If two-factor authentication ever locks administrators out, add `define('CLOCKWORK_2FA_DISABLE', true);` to `wp-config.php`.

= When does the maintenance page appear? =
Visitors get the plugin's 503 maintenance page while Clockwork Control restores a backup, or when your operator switches maintenance mode on. wp-admin and `wp-login.php` stay reachable, `admin-post.php` only for signed-in users, and WP-CLI always works, so `wp` commands can be used to recover. While an active plugin is being updated, WordPress core's own short maintenance notice is shown instead.

= Which wp-config.php constants does it read? =
All are optional.

* `CLOCKWORK_RENEGADE_TRUSTED_PROXIES`: proxies whose forwarding headers are trusted (see above).
* `CLOCKWORK_RENEGADE_TRUST_PROXY`: legacy switch; trust Cloudflare and private networks when no proxy list is set.
* `CLOCKWORK_GATEKEEPER_DISABLE`: switch Gatekeeper off on this server, whatever the stored policy says.
* `CLOCKWORK_2FA_DISABLE`: rescue switch that skips the two-factor login step.
* `CLOCKWORK_2FA_FORCE_ENROLLMENT`: nag agency administrators who have not enrolled, then lock wp-admin to the Login Security page after a grace period (`CLOCKWORK_2FA_GRACE_DAYS`, default 30).
* `CLOCKWORK_BACKUP_DIR`: directory for backup and restore work files, preferably outside the web root. Without it, the plugin uses a directory beside the WordPress root when that is outside the web root, otherwise a protected folder in uploads.
* `CLOCKWORK_RENEGADE_SECRET`: pins the connection secret in `wp-config.php` instead of the database (remote secret rotation is then refused).
* `CLOCKWORK_RENEGADE_DISABLE_SELF_UPDATE`: turn off updates from GitHub Releases.
* `CLOCKWORK_AGENCY_EMAIL_DOMAINS`: email domains whose users count as agency staff.
* `CLOCKWORK_SUPPORT_SITE_URL`, `CLOCKWORK_SUPPORT_GF_FORM_ID`, `CLOCKWORK_SUPPORT_GF_FIELD_MAP`, `CLOCKWORK_SUPPORT_GF_KEY`, `CLOCKWORK_SUPPORT_GF_SECRET`: the in-dashboard support form. It sends tickets to a Gravity Forms form on your agency's site (the site URL, form id, an optional map of field names to Gravity Forms inputs, and REST API v2 keys). Without a site URL and form id the form says it is not configured. The `clockwork_renegade_support_form` filter sets fixed values, a billing-terms checkbox or a newsletter opt-in.

= Does this plugin run arbitrary code remotely? =
No. Clockwork Renegade does not execute arbitrary remote code or eval(). All updates and maintenance operations strictly invoke WordPress core's native APIs (`Plugin_Upgrader`, `Theme_Upgrader`, `Core_Upgrader`, etc.).

== Screenshots ==

1. Connection pairing screen with one-click cryptographic key copying.
2. Site activity and operation audit history.
3. System telemetry and resource performance diagnostics.

== Changelog ==

= Unreleased =
* Security: Close a REST nonce (CSRF) bypass in the wp-force-login compatibility filter. It approved REST authentication whenever the URL contained the plugin's namespace, even in a query string, which also skipped core's cookie-nonce check for any REST route. It now acts only on the plugin's own dispatched routes, as an anonymous user.
* Security: Gatekeeper no longer exempts a login request whose URL merely mentions the plugin's REST namespace; only a dispatched `clockwork-renegade/v1` route is exempt.
* Security: Gatekeeper trusts `CF-Connecting-IP` / `X-Forwarded-For` only from configured trusted proxies (Trusted Proxies setting, `CLOCKWORK_RENEGADE_TRUSTED_PROXIES`, or the `clockwork_renegade_trusted_proxies` filter; Cloudflare ranges via `clockwork_renegade_cloudflare_ranges`). An invalid header falls back to the connecting address instead of a shared bucket. The admin tab warns when your own request resolves to a private address or arrives unproxied from Cloudflare.
* Security: Gatekeeper reserves attempts atomically before the password check, so parallel requests cannot exceed the threshold; a successful login no longer clears an address's earlier failures; IPv6 is counted per /64; consecutive lockouts are forgotten after 24 hours; on multisite the lockout table and policy are network-wide (schema version 2 migrates per-site tables).
* Security: Each signed request is verified once per dispatch, so WordPress re-checking permissions after a mutating request no longer records a replay against Control's own address. A valid signature is never rate-limited; failures over the limit get a bare 429 with nothing written.
* Security: Replay markers now outlive signatures stamped in the future, and a signed request with an empty body but form or file fields is refused.
* Security: Connection-secret rotation is two-phase: the new secret stays pending until Control first uses it, so a lost response no longer disconnects the site. An empty `CLOCKWORK_RENEGADE_SECRET` constant no longer breaks every request.
* Security: SSO link creation and use, secret rotation and plugin install/delete/activate/deactivate are recorded in the site's own activity log (never secrets or link tokens).
* Security: Every response from the plugin's REST namespace is sent with `Cache-Control: no-store`, so page caches and CDNs cannot store and replay signed responses.
* Security: Two-factor codes are limited per account (10 wrong codes in 15 minutes lock the code step for 15 minutes; filter `clockwork_renegade_2fa_max_failures`). Wrong codes fire `wp_login_failed`, count towards Gatekeeper, and are refused from an address Gatekeeper has locked; a completed challenge fires `wp_login`.
* Security: Users with 2FA enabled can no longer log in with a password alone over XML-RPC or the REST API. Application passwords still work.
* Security: Turning 2FA off, regenerating backup codes or replacing an active authenticator now requires a current code, and each change or refused attempt is logged.
* Security: When a GitHub release publishes a `.zip.sha256` checksum (the release workflow does), the self-updater verifies the downloaded zip against it and refuses a mismatch.
* Security: Lockout and Wordfence block lists sent to Control drop private, loopback, link-local, reserved and CGNAT addresses and are capped at 500 entries; Wordfence block reasons are sanitised.
* Security: Backup and restore work files get random names and are kept outside the web root when possible (`CLOCKWORK_BACKUP_DIR`, `clockwork_backup_staging_dir` filter); leftovers are swept automatically.
* Security: The malware probe recognises harmless index stubs by tokenising them, scans uploads and signatures with separate budgets, matches more PHP extensions, reports `.htaccess` / `.user.ini` handler overrides in uploads and lists skipped large files. The core checksum check reports unexpected PHP, `.htaccess` and `.user.ini` files in `wp-admin` and `wp-includes`.
* Fix: Database backups no longer corrupt every `%` character. Older archives are repaired automatically when restored.
* Fix: Maintenance mode now actually engages (it was never hooked in time). A site whose stored maintenance flag is still on will show the maintenance page after this update. Anonymous `admin-post.php` requests get the maintenance page; WP-CLI always passes.
* Fix: Database dumps are consistent snapshots paged by key, verified after writing, and skip views; the importer refuses truncated or mismatched dumps before running anything.
* Fix: A restore keeps the plugin's own connection secret, restore status and maintenance state through the database import, and every dump is verified before the site is touched.
* Fix: Default backup and restore exclusions are anchored to `wp-content`, so plugin folders named `cache` or `node_modules` deeper in the tree are backed up.
* Fix: Active plugins are updated under maintenance mode and the site is checked for a fatal error afterwards; a broken update is rolled back or the plugin deactivated.
* Fix: Pushing branding from Control no longer wipes settings the push did not include, and accepts agency email domains.
* Fix: Core updates prefer the latest release on the site's current branch, and any X.Y change counts as a major update.
* Fix: Stale "X → X" theme and plugin update entries are ignored.
* Fix: A failing update-check loopback is remembered for 15 minutes instead of being retried on every request.
* Fix: Unknown SSO link tokens no longer bloat the object cache, and one link cannot be redeemed twice at the same time.
* Fix: Wordfence Login Security migration keeps the old secret until the new 2FA settings are saved.
* Fix: Contact-form lab mode no longer stores entries, runs feeds or integrations, or sends mail through SMTP plugins that replace `wp_mail()`.
* Fix: The privacy-policy text is registered on `admin_init`, so it reaches the privacy guide without a notice.
* Fix: Apostrophes in Gatekeeper settings and support tickets are saved without backslashes.
* Fix: Uninstalling removes the plugin's real tables, 2FA secrets, cron event and work files on every site of a network. Data shared with Clockwork Companion (Gatekeeper lockouts and policy, 2FA settings and others) is kept while Companion is installed, even if it is deactivated.
* Fix: Loading the plugin twice in one request no longer causes a fatal error.
* Fix: On multisite, listing lockouts reads the same places clearing them does.
* Change: The support form is configured through `CLOCKWORK_SUPPORT_GF_FORM_ID`, `CLOCKWORK_SUPPORT_GF_FIELD_MAP` and the `clockwork_renegade_support_form` filter, with neutral defaults: no form, no billing checkbox, no newsletter opt-in.
* Change: Only configured agency email domains (and the support address) count as agency users.
* Change: `CLOCKWORK_RENEGADE_TRUST_PROXY` now means "trust Cloudflare and private networks" when no trusted-proxy list is set. A site behind a public-IP proxy that is not Cloudflare must list it under Trusted Proxies.
* Change: Fewer database queries per page view; the last-contact time is written at most once a minute.
* Change: Backup archives are written in a single pass, needing less disk space and I/O.
* Change: Known limitation: with Clockwork Renegade and Clockwork Companion both active on one site, each failed login is counted twice.

= 1.1.4 - 2026-11-11 =
* Security: Enforce `X-Clockwork-Signature-V2` canonical query signing and atomic `INSERT IGNORE` replay protection in `HmacVerifier`.
* Security: Reject symlink/hardlink and path-traversal entries during backup archive extraction, enforce `WP_CONTENT_DIR` `realpath()` containment on file restores, and validate HTTPS/SSRF/host allowlists on S3 transfers and GitHub self-updates.
* Security: Use `wp_safe_redirect()` with `Referrer-Policy: no-referrer` on SSO logins, validate proxy headers against trusted `REMOTE_ADDR` ranges in `MaintenanceGuard`, strip CR/LF in notification headers, cap `debug.log` tail reads via `fseek()`, escape `LIKE` wildcards in `DatabaseRoute`, and skip symlinked directories in `MalwareScanner`.
* Fix: Preserve commercial plugin and theme update transient packages across REST-context update checks, flush transient object-cache keys after loopback refresh, and surface `WP_Upgrader_Skin` error messages on failed upgrades.
* Fix: Allow `cleanup` in `CommentsActionRoute` REST argument validation.

= 1.1.3 - 2026-11-04 =
* Fix: Preserve active plugins in-place during single-plugin upgrades, flush object cache (`alloptions` and `active_plugins`), and maintain plugin load order to prevent concurrent shutdown hooks from overwriting `active_plugins`.
* Fix: Pass the inner `$_POST['wpforms']` entry array to `wpforms()->process->process()` during WPForms contact form health checks and support `first-last` name subfields and choice labels.

= 1.1.2 - 2026-10-28 =
* Feature: Backup manifests — collect detailed metadata (database table row counts & sizes, uploads, plugins, themes, exclusions) and embed manifest.json in backup archives.
* Feature: Visual backup breakdown in WP Admin Backups table.

= 1.1.1 - 2026-10-28 =
* Fix: Two-factor authentication is optional; removed forced enrollment countdown notice and admin lockout.
* Fix: Enforce HTTPS scheme on bundled assets (admin.css, logo, scripts) to prevent browser mixed-content blocks on SSL sites.
* Fix: Restore support button styling and add header background fallback.
* Fix: Direct S3 archive backups recognize both companion and renegade sources.

= 1.1.0 - 2026-10-28 =
* Self-updater: installs not distributed via WordPress.org now receive updates through the normal WordPress updater, sourced from GitHub Releases. Disable with `define('CLOCKWORK_RENEGADE_DISABLE_SELF_UPDATE', true);`.
* Care Plan report recipients: manage who receives Care Plan & Maintenance Reports from the Notifications tab. New signed `/client-notifications` endpoint syncs recipients, frequency, and on/off state with Clockwork Control.

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
