=== Dashi ===
Contributors: jidaikobo
Donate link: http://www.jidaikobo.com/donate.html
Tags: custom field, custom post type
Tested up to: 7.1
Stable tag: 3.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires PHP: 7.1

Useful classes for creating a custom post type. Creates Page Parts custom post type. Designed for theme developers.

== Description ==

Dashi helps WordPress theme developers define custom post types and fields in a theme's `posttypes` directory. It also provides features for site administrators.

* Reuse editable content across pages with Page Parts.
* Extend site search to include eligible archive pages and the home page. Start a search crawl manually from Dashi settings.
* Accept public form submissions and keep attachments private while they await review.
* Schedule replacements for published posts.
* Export selected post types to CSV from the dashboard.
* Review Contact Form 7 recipient warnings and specify trusted domains.
* Enable WordPress alternate cron spawning when WordPress cannot reach its own cron URL.

Before updating to 3.5.0, review pending attachments from older versions. A user with publishing and replacement permissions must reopen and save existing scheduled replacements before their scheduled publication date.

See the [developer documentation](https://github.com/jidaikobo-shibata/dashi/blob/main/README.md#operational-notes) for storage limits, visibility rules, filters, and integration requirements.

= External services =

**jPostal.** When postal-code lookup is enabled for a form, Dashi sends the first three digits of the entered Japanese postal code over HTTPS to `jpostal-1006.appspot.com` on a cache miss. The service receives the site's server IP address. The browser matches the full postal code locally. See the [jPostal project documentation](https://github.com/ninton/jquery.jpostal.js). A service-specific privacy policy or terms of use could not be located.

**Google Maps.** Configuring a Google Maps API key enables map fields. The browser sends the API key to Google when loading the script and submits addresses for geocoding. An embedded map on a public form confirmation sends the map coordinates and zoom level. Google also receives the browser's IP address. See the [Google Maps Platform Terms of Service](https://cloud.google.com/maps-platform/terms) and [Google Privacy Policy](https://policies.google.com/privacy).

== Installation ==

1. Install and activate Dashi from the WordPress Plugins screen.
2. Open Settings → Dashi to configure the features you use.


== Changelog ==

= 3.5.1 =
* Exclude the WordPress author sitemap when author archives are disabled in Dashi settings.
* Require authentication to read the standard REST API user list and individual user records by default. You can turn this off in Dashi settings.
* Allow 30 minutes before showing crawl delay notices and show the next scheduled crawl time.
* Shorten the plugin description and move detailed notes and older changes to the developer README.

= 3.5.0 =
* Corrected the minimum PHP requirement to 7.1 to match the code.
* Store unapproved public form attachments in the database and enforce upload limits, ownership, and expiration.
* Tightened permissions for search, copying, previews, revisions, CSV export, and dashboard widgets.
* Hardened scheduled replacements and made notification retries track completed deliveries.
* Load date and time picker assets only where needed, and corrected time zone handling when saving date and time fields.
* Added an option to use WordPress alternate cron spawning.
* Upgrade note: a user with publishing and replacement permissions must reopen and save existing scheduled replacements to renew their approval before the scheduled publication date.

= 3.4.15 =
security: preserve visibility restrictions when extending search, including attachment filename searches
security: verify public upload ownership and validate all public form submissions
security: restrict autosave previews and copying according to post permissions
security: allow only valid heading tags in the sitemap shortcode
fix: prevent approved uploads from overwriting existing files
fix: apply custom field filters correctly and in sequence
fix: preserve upload receipts across confirmation and back actions, and prevent repeated submissions
note: review legacy pending attachments before updating; files selected in existing forms may need to be uploaded again

= 3.4.14 =
security: harden settings, replacement posts, taxonomy fields, maps, and uploads
fix: preserve public form values when returning from the confirmation screen
fix: keep public form confirmation fields in input order
fix: avoid PHP 8.1 deprecation warnings for empty public form mail values
fix: show Page Parts edit links according to WordPress capabilities

[Earlier releases](https://github.com/jidaikobo-shibata/dashi/blob/main/README.md#earlier-releases)

== Upgrade Notice ==

= 3.5.1 =
Anonymous reads of standard WordPress REST API user data now require authentication by default. Review frontends or external applications that use these endpoints without authentication.

= 3.5.0 =
Requires PHP 7.1 or later. A user with publishing and replacement permissions must reopen and save existing scheduled replacements before their publication date. Review legacy pending attachments before updating.

= 3.4.15 =
Security update for search, previews, copying, sitemap output and public uploads. Review legacy pending attachments before updating. Files selected in existing forms may need to be uploaded again.

== Frequently Asked Questions ==

= What database storage do public form attachments require? =

Submitting a public form with database-backed attachments requires InnoDB for the WordPress posts and postmeta tables and the Dashi upload table. Dashi uses its own database transaction to confirm attachment references and transfer ownership together. Integrations must finish any open database transaction before submitting a public form.

= How do postal lookup limits work behind a reverse proxy? =

Configure the web server to restore the visitor IP in `REMOTE_ADDR` when possible. Otherwise, define `DASHI_POSTAL_TRUSTED_PROXIES` in `wp-config.php` as an array of exact proxy IP addresses. Configure those proxies to overwrite `X-Dashi-Client-IP` with a single verified visitor IP for every request and strip any client-provided value. The postal limiter accepts that header only from a listed proxy. Public IPv6 fetches are limited by /64. The default trusts the web server's `REMOTE_ADDR`.


= How do I invalidate crawl snapshots when changing WP_HOME? =

When changing or restoring `WP_HOME` in `wp-config.php`, set `DASHI_CRAWL_CONFIG_EPOCH` to a new value in the same deployment, for example `define('DASHI_CRAWL_CONFIG_EPOCH', '2026-11-22-1');`. Use a value that has never been used for that site, even when rolling back the URL. This prevents reuse of previous snapshots even if WordPress could not record the URL change in the database. Snapshots become searchable again after a fresh crawl.
