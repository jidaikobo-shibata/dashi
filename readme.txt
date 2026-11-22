=== Dashi ===
Contributors: jidaikobo
Donate link: http://www.jidaikobo.com/donate.html
Tags: custom field, custom post type
Tested up to: 7.1
Stable tag: 3.4.15
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires PHP: 7.1

Useful classes for creating a custom post type. Creates Page Parts custom post type. Designed for theme developers.

== Description ==

Useful classes for creating a custom post type.

You gains below by installing this plugin.

=== Page Parts ===

A custom post type called Page Parts is created. This custom post type makes easy to edit a part of page.

=== Enhance search results ===

Enhance site search. make able to search archive page and home.

=== Help to add new custom post types and its custom fields ===

Easy to add custom post types by your theme.
This plugin read specified directory called `posttypes' in your theme directory.
please check `Settings' -> `Dashi' to know how to add custom post types.

[translate by using GlotPress](https://translate.wordpress.org/projects/wp-plugins/dashi)

= Public form attachments =

Public form attachments are stored in the WordPress database. The limits are 1 MiB per file, 4 MiB across unsubmitted files for a form in one browser session, 16 MiB for unsubmitted files per site, and 48 MiB reserved for attachments awaiting review, including uploads reserved for submission. Approved attachments use the shared 128 MiB site storage limit. Smaller WordPress upload limits still apply. The Dashi settings screen shows unsubmitted, review, and approved usage separately. Exclude public form and attachment URLs from page caching. Opening a public form for the first time issues short-lived signed tokens. Dashi starts a browser session when the first validated submission or upload passes its rate limit. Confirmation, final submission, and uploads each allow up to ten requests per IP address per minute. IPv6 also has a limit of sixty requests per /64 network per minute for each operation. Automatic replies are attempted only after the administrator notification has been accepted for sending. Automatic replies have separate limits and their result does not decide whether the form has been accepted.

If the form requires image metadata removal, it needs Imagick. Images are rejected when metadata cannot be removed. Dashi checks storage availability before converting an image and processes one image at a time per site. Image processing is limited to 4,096 pixels per side, 32 frames, and 16,777,216 pixels across all frames. The form does not include original EXIF data in its mail when removal is required. Pending images whose metadata removal has not been verified remain in temporary storage and must be uploaded again before they can be published.

Reopening a form keeps the current draft in that browser session. Use Discard draft to clear it and release storage occupied by its unsubmitted uploads. A form rendered after HTTP headers have been sent can start a session from a same-origin form submission.

The most recent confirmation for a form in a browser session is valid for 30 minutes. Going back to edit or completing the submission invalidates that confirmation. Confirmation requests are limited to 10 per minute per IP address. Confirmation input is limited to 256 submitted values, 16 KiB per value, and 64 KiB of submitted field data before processing. Attachment bytes count toward the separate upload limits.

Attachment upload grants last 30 minutes and are tied to the page hosting the form. Both grant issuance and upload acceptance check the page's publication status, password protection, post query restrictions, and the `dashi_search_can_show_result` visibility filter. For access rules that run only when rendering pages, also apply the rule to the supplied `WP_Post` through this visibility filter so it applies to uploads.

Editing the hosting page's saved content or changing its publication status or password invalidates its existing upload grants. Restoring the page's previous state still requires new grants. If a theme or a separate Page Part renders the form, use the `dashi_public_form_upload_revision` filter (revision string, hosting `WP_Post`, form name). Return a stable string that changes when that form's configuration changes, or return false to disable upload grants for that form and page.

Unsubmitted attachments expire after 24 hours by default and are deleted by WP-Cron. A temporary preview link requires the same browser session that uploaded the file. If an attachment is included in a notification without an associated post, the notification contains a separate expiring link that requires a signed-in user with the WordPress `manage_options` capability. After the administrator notification is accepted for sending, Dashi aligns the temporary attachment’s storage deadline with the mail link. If sending fails, the attachment retains its previous deadline for retry. Expired temporary files do not count against the active upload quota. A file field can opt into public downloads with `public_form_publish_attachment => true`. Dashi keeps the bytes in the database and checks the current submission and field settings on each public download. The submission must be published and publicly viewable, without a password or an applicable access policy. The download route loads the parent post before the standard template redirect hooks run. Use the `dashi_public_form_can_publish_attachment` filter for access rules enforced elsewhere, such as content renderers. Other downloads require a signed editor link and permission to edit the submission. Dashi does not create a Media Library item or featured image when approving these files. Legacy temporary files remain editor-only. Static files already made public by older versions remain accessible under their original URLs until removed from public storage.

`store_data => false` excludes a field from submission storage, including title and content mappings and attachment associations. Temporary attachments supplied solely for notification remain subject to the temporary upload expiry. `public_form_allow_send_by_mail => false` excludes a field from both the administrator notification and the automatic reply.

Automatic replies use a fixed acknowledgement by default and have a per-site limit of three attempts per recipient per hour. Admin notification mail uses the configured sender or the WordPress administration address. A valid address entered by the visitor is used as Reply-To. Dashi stores submission progress and rate counters in a site-specific database table with indexed expiration. The table is limited to 16,384 active records, including at most 8,192 rate counters. When a temporary database failure prevents checking a recipient's limit, the reply stays pending and the same confirmation may be retried. When the same confirmation is retried, Dashi reuses its saved post and skips mail already accepted. If an interruption leaves the result uncertain, Dashi asks the visitor to contact the site administrator before submitting again.

Public form values mapped to post content are stored as plain text. If a form requires a post and it cannot be saved, the form returns to confirmation without sending notification mail. Dashi blocks post replacement while either post holds private public-form attachments, so the original file and its authorization are retained.

An admin-only field group also excludes its children from the public form. Removing a private attachment reference blocks download immediately. Saving the submission deletes the detached file while keeping other attachments.

Moving a submission to the trash frees its space in the review queue. Its attachments remain available for restoration and still count toward the site's total attachment storage limit until the submission is permanently deleted.

Uploads received before the database storage change remain in their original storage. Dashi leaves those files in place during post saves and scheduled cleanup. Review them before removing or migrating them manually. Use the link in the submission editor to download a previously submitted legacy file. Unsubmitted legacy files may need to be uploaded again. Keep direct HTTP access to the legacy `dashi_uploads` directory blocked at the web server while these files remain. Dashi supplies an Apache access file when one is missing. For Nginx, a proxy, or a CDN that serves the uploads directly, configure and verify the restriction separately. The Dashi administration screen warns while legacy files remain.

= HTTPS enforcement =

When the Dashi HTTPS setting is enabled, HTTP requests for frontend pages are redirected for GET and HEAD. Public form submissions, uploads, direct attachment downloads, and postal-code lookups require HTTPS. Configure your trusted reverse proxy or web server so that WordPress recognizes HTTPS correctly. User-Agent values do not bypass the check.

= Copies of restricted posts =

Copying a private or password-protected post requires publishing permission for that post type. Dashi opens the copy in the classic editor with the source visibility selected. The editor can change the visibility before publishing the copy. If a Dashi search visibility filter, a replacement visibility filter, or a WordPress password filter is active, Dashi requires explicit approval to copy a post. Integrate other membership rules using `dashi_can_copy_post_visibility` (decision, source `WP_Post`). Return true only when copying this source is safe under the site’s access rules. Return false to disable copying for that source. Approval still requires the standard WordPress capabilities.

= Scheduled post replacements =

Scheduled post replacements require approval of the original post, replacement post, and the exact content, custom fields, and terms being applied. A user with publishing and replacement permissions must reopen and save replacements scheduled before this update to renew approval before their publication date. A changed, password-protected, private, or unapproved replacement is held for review. Post, metadata, and taxonomy changes are applied in a database transaction. The WordPress post and taxonomy tables must use InnoDB. A scheduled maintenance task recovers at most 20 overdue replacements per run.

Publishing a linked replacement through the REST API leaves it pending. Use `Dashi\Core\Posttype\Another::canPublishDirectly($post_id) === true` before calling `wp_publish_post()` from an integration. A direct publication of a replacement ends that request with HTTP 409 before default term relationships are added or publication hooks run. The classic replacement editor can apply the change directly when the user has publishing and replacement permissions. Use the `dashi_replacement_committed` action for integrations after a replacement commits. Transactional replacements use this dedicated action in place of the standard post save actions. The arguments are the original post ID, the replacement post ID, and a callable that returns a freshly verified public post or null. Register the callback with three accepted arguments and call the verifier immediately before each external delivery. Skip delivery if the callable returns null. Per-field and taxonomy write hooks are not dispatched for the transactional copy. An applied replacement is kept as a draft and excluded from active replacement candidates. Notification processing never deletes the source. Editing or deleting an archived source that was scheduled or published requires the corresponding published-post capability for that post type. Dashi checks the current post and its visibility again between notification stages. Only one worker processes each notification at a time. An interrupted notification is retried by maintenance when it becomes due. Built-in mail retries are limited to recipients with a confirmed failure. If a mail attempt is interrupted before its result is recorded, Dashi uses the server error log to report the uncertain delivery without queuing another send for that recipient. Each maintenance run reserves capacity for overdue replacements separately from notification retries. If the original changes after commit, including a change to visibility, Dashi clears the stale event without sending external notifications. Legacy records without a version are processed for local cleanup only. Dashi records completion for callbacks executed by `dashi_replacement_committed`, including callbacks added during execution. Register callbacks in each request so they are available on retries. Integrations can deduplicate an interrupted delivery using the original and replacement IDs.

Use `dashi_can_replace_post_visibility` (decision, original post, replacement post) to check membership or other custom access rules. Return true where the destination's visibility is appropriate and false to hold the replacement. If a Dashi search access filter or a post password filter is registered, Dashi requires an explicit decision. Other membership rules must be connected to this filter by the site. Password-protected and private replacement sources remain held. External replacement notifications are limited to published, publicly viewable posts without passwords. Use `dashi_can_dispatch_replacement_notification` (decision, current original post, replacement post ID) to check custom access rules immediately before sending. Return true only if external delivery is allowed. When a Dashi visibility or search filter or a WordPress password filter is active, an explicit allow decision is required.

= Search and membership restrictions =

When Dashi search enrichment is enabled, body and excerpt matches use indexed visible text. Existing published posts are reindexed in batches by WordPress cron. Until a post is reindexed, HTML in its body and excerpt is omitted from matching. Configure public-search restrictions in field definitions or in a `dashi_mod_custom_fields` callback registered for admin, frontend, and cron requests. Fields with callable options are excluded from the public supplementary index. Dashi skips supplementary matches for a slug when its field definition cannot be loaded.

For site-specific access control, use the `dashi_search_can_show_result` filter (arguments: whether to show the result, and the `WP_Post`) to hide result entries from users who cannot read them. Registering this filter or a `post_password_required` filter disables Dashi's additional index matches, including Page Parts, because PHP access checks are not available inside the SQL query. Apply the same restrictions to the WordPress search query to control result counts, pagination, and matching against WordPress's regular search columns. Dashi also applies these visibility checks and WordPress query filters to the post reference picker and Dashi’s HTML sitemap. The Page Part shortcode checks the same visibility filter before rendering a referenced post. CSV exports honor WordPress query filters and this visibility filter in addition to edit permissions. Editable help tabs also check this visibility filter. Cyclic references are skipped. Embeddings are limited to 16 levels and 128 Page Part render attempts per request.

Page Parts are excluded from public search by default, including matches through pages that embed them. To include Page Parts whose content is public to everyone, return an array of integer post IDs from the `dashi_search_indexed_pagepart_ids` filter. Include only Page Parts whose content can be indexed regardless of any rules applied by `the_content` filters.

= Date and time input =

Dashi loads its legacy picker assets when it renders a field with the `dashi_datepicker` or `dashi_datetimepicker` class. If your theme writes the input markup directly, call `Dashi\Core\Field::enqueueDatePicker()` before WordPress prints the footer scripts. Pass `true` to include the time picker.

Dashi indexes only HTML or XHTML responses. It discovers links from home page anchor elements outside hidden elements and templates when the PHP DOM extension is available. URLs listed in Dashi settings are checked independently. Relative links use the document base URL. All crawl URLs must be within the origin and path of the WordPress home URL. Home URL changes, including through `WP_HOME`, invalidate old snapshots and crawl candidates when WordPress runs. Restoring an earlier URL requires a fresh crawl. Public queries also exclude snapshots whose source URL, title, or body differs from the crawled version. When the home page is unavailable, the crawler uses its last verified links and the current settings. Until links have been verified, an existing entry is checked for access denial but is refreshed only if explicitly configured or if it is the home page. Entries that are no longer eligible are hidden. The crawler requests revalidation and skips successful responses identified as cache hits. It hides entries on HTTP 401, 403, 404, or 410 and redirects to the WordPress login endpoint. It also hides pages with a general `noindex` directive in the HTTP headers or the HTML head. For other access-denied redirects, use the `dashi_crawl_access_denied_redirect` filter (decision, crawled URL, absolute redirect destination, HTTP status). Return true to hide the entry. Public WordPress queries exclude older index formats until refreshed and expire entries after seven days without a successful refresh.

= WordPress cron =

If WordPress cannot reach its own cron URL, enable “Use WordPress alternate cron spawning” in Dashi settings. This turns on the WordPress core `ALTERNATE_WP_CRON` behavior for that site. It applies to WordPress scheduled tasks, including scheduled posts and any enabled automatic updates. The option defaults to off. Constants in `wp-config.php` take precedence. A page request must reach WordPress to start cron, and the browser may be redirected to a URL containing `doing_wp_cron`.

== External Services ==

= jPostal =

Dashi uses [Ninton's jPostal service](https://www.ninton.co.jp/archives/1603) to fill in Japanese addresses on forms configured for postal-code lookup. When the user enters the first three digits of a postal code, the site’s WordPress server sends that prefix over HTTPS to `jpostal-1006.appspot.com` when the result is absent from its cache. Per minute, 25 public and five admin fetches per site, five per user or IP. Cache hits are exempt. The service receives the server's IP address as part of the request. The server validates the address data and returns JSON to the form. The full postal code is matched in the browser. No account is required. See the [jPostal project documentation](https://github.com/ninton/jquery.jpostal.js). A service-specific privacy policy or terms of use could not be located.

= Google Maps =

Dashi uses [Google Maps Platform](https://mapsplatform.google.com/) to display map fields and look up addresses. Configuring the Google Maps API key in Dashi enables loading the Maps JavaScript API in the browser. Google receives the API key when the script loads and any address submitted for geocoding. When a public form confirmation displays an embedded map, the browser sends the map coordinates and zoom level to Google. Google also receives the browser's IP address when these requests are made. See the [Google Maps Platform Terms of Service](https://cloud.google.com/maps-platform/terms) and [Google Privacy Policy](https://policies.google.com/privacy).

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/plugin-name' directory, or install the plugin through the WordPress plugins screen directly.

1. Activate the plugin through the 'Plugins' screen in WordPress

1. Use the Settings -> Plugin Name screen to configure the plugin

== Changelog ==

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

= 3.4.13 =
fix: keep generated search metadata out of post edit submissions

= 3.4.12 =
fix: restrict dashboard widgets and Dashi admin notices by capability

= 3.4.11 =
chore: update tested WordPress version

= 3.4.10 =
fix: improve public form admin mail sender and edit links

= 3.4.9 =
fix: limit public form session cookie to relevant pages and improve confirm action buttons

= 3.4.8 =
fix: preserve textarea and wysiwyg values in preview revisions

= 3.4.7 =
add: Playwright E2E tests for admin custom field flows

= 3.4.6 =
fix: restore CSV export dashboard post type select rendering

= 3.4.5 =
fix: avoid front-end redirect loop to /wp-admin/ in keep SSL connection mode

= 3.4.3 =
chore: bump patch version for jats verification

= 3.4.4 =
chore: bump patch version for Plugin Check and release flow updates

= 3.4.2 =
chore: bump patch version for WordPress.org SVN update flow

= 3.4.1 =
chore: bump patch version for waic verification

= 3.4.0 =
maintenance: internal refactoring and project path reorganization

= 3.3.4 =
add: filter input for select/radio/checkbox options (opt-in via `filter`)
add: help text for filter option in Option help
add: visible label for filter input
fix: make filter input block-level for layout consistency

= 3.3.3 =
security: require signed temporary file URLs (`path`, `exp`, `sig`) in `file.php`
security: add nonce/capability checks for `custom_referencer` AJAX
security: add rate limiting and nonce validation for public uploader AJAX
security: mitigate spreadsheet formula injection in CSV export
fix: show non-image uploads (e.g. PDF) as links on public form confirm screen

= 3.3.1 =
fix: resolve custom field options lazily to avoid undefined array key warnings
fix: stabilize handling of options in select / radio / checkbox fields
note: minimum required PHP version is now 7.0

= 3.3.0 =
add tiny class to seach template

= 3.2.9 =
fix around addCustomColumn

= 3.2.8 =
fix manage_[custom post type]_posts_custom_column

= 3.2.7 =
fix lat and lng

= 3.2.6 =
fix array key

= 3.2.5 =
add file_media type to custom_fields

= 3.2.4 =
Enhance Google Map usability

= 3.2.3 =
add more custom fields to taxonomy

= 3.2.2 =
security review "Posttype::class"

= 3.2.1 =
secure search logic

= 3.1.10 =
fix several typos

= 3.1.9 =
fix path traversal

= 3.1.8 =
fix sashikae issue

= 3.1.7 =
add WP-CLI check

= 3.1.6 =
fix posttype/save

= 3.1.5 =
fix Deprecated issue

= 3.1.4 =
fix version number

= 3.1.3 =
fix issue of `load_textdomain`

= 3.1.2 =
fix bug of `show_in_rest`

= 3.1.1 =
eliminate useless code

= 3.1.0 =
better save_post hook

= 3.0.9 =
fix replacement not working (Dashi\Core\Posttype\Another)

= 3.0.8 =
$wp_rewrite->flush_rules(true) -> $wp_rewrite->flush_rules(false)

= 3.0.7 =
better redirect

= 3.0.6 =
fix advanced search problem

= 3.0.5 =
fix php 8.0 not allow null argument at some built-in functions

= 3.0.4 =
fix pre_wp_unique_post_slug Hook 2

= 3.0.3 =
fix pre_wp_unique_post_slug Hook

= 3.0.2 =
fix NotationDomain

= 3.0.1 =
fix posttype/another.php

= 3.0.0 =
support show_in_rest

= 2.9.9 =
exapnd dashi_enrich_search_result_page

= 2.9.8 =
fix js uploader

= 2.9.7 =
add new checklist (ACL)

= 2.9.6 =
better dashi_search

= 2.9.5 =
fix session timing

= 2.9.4 =
fix is_public_searchable
fix admin_enqueue_scripts at Dashi\Core\Posttype\Posttype

= 2.9.3 =
fix avoid access to author=1

= 2.9.2 =
fix wrong query access.

= 2.9.1 =
fix class check.

= 2.9.0 =
fix slug problem with BOGO and add class check.

= 2.8.9 =
fix revision behaviour
add check sequence same custome field name

= 2.8.8 =
fix validation hiragana/katakana

= 2.8.7 =
add menu_name for label
fix bug of revision

= 2.8.6 =
fix post-new custom_fields problem

= 2.8.6 =
fix post-new custom_fields problem

= 2.8.5 =
maintenance

= 2.8.4 =
better search logic

= 2.8.3 =
fix 'exclude_from_search'

= 2.8.2 =
remove Debug code

= 2.8.1 =
fix error handling

= 2.8.0 =
fix enable filter at admin index

= 2.7.9 =
hot fix: enable filter at admin index

= 2.7.8 =
enable filter at admin index

= 2.7.7 =
suppress error

= 2.7.6 =
syntax error

= 2.7.5 =
fix curl related issue

= 2.7.4 =
fix curl security issue

= 2.7.3 =
fix duplicatable custom_fields

= 2.7.2 =
fix auto update language issue

= 2.7.1 =
fix author=1 problem

= 2.7.0 =
fix author=1 problem

= 2.6.9 =
Better Excel compatible CSV

= 2.6.8 =
be able to delete taxonomy custom fields

= 2.6.7 =
csv for Microsoft Excel

= 2.6.6 =
fix file uploader bug

= 2.6.5 =
add datetime picker

= 2.6.4 =
fix google map bug

= 2.6.3 =
add csv export

= 2.6.2 =
fix copy function
fix requirement check of public_form's field at admin edit

= 2.6.1 =
add "No Subject" to search result page

= 2.6.0 =
remove useless post_type information at search result

= 2.5.9 =
remove search pre_get_post hook at admin page

= 2.5.8 =
fix count miss of templates/search.php

= 2.5.7 =
visible pagepart edit link

= 2.5.6 =
error suppress

= 2.5.5 =
add option avoid heavy dashboard check

= 2.5.4 =
search function backward compatible

= 2.5.3 =
error suppress

= 2.5.2 =
fix...

= 2.5.1 =
fix...

= 2.4.8 =
better null byte search string 2

= 2.4.8 =
fatal fix!

= 2.4.7 =
better null byte search string

= 2.4.6 =
check null byte search string 2

= 2.4.5 =
check null byte search string

= 2.4.4 =
add enter title here

= 2.4.3 =
pageparts able to be removed

= 2.4.2 =
multisite support

= 2.4.1 =
better permission message

= 2.4.0 =
better logic dashi_notation_display_error

= 2.3.9 =
check directory permission

= 2.3.8 =
implement post copy

= 2.3.7 =
fix index behaviour (taxonomy sort)

= 2.3.6 =
fix template

= 2.3.5 =
fix dashi_sitemap

= 2.3.4 =
fix public form sendmail bug and google map bug

= 2.3.3 =
add custom fields to taxonomy

= 2.3.2 =
better indexing logic

= 2.3.1 =
fix notices

= 2.3.0 =
revert 2.2.9. it was bad code

= 2.2.9 =
return better response header when HEAD request given

= 2.2.8 =
fix WYSIWYG attr bug

= 2.2.7 =
add another to current_user_can('edit_published_posts')

= 2.2.6 =
hide help

= 2.2.5 =
lower php compatibleness

= 2.2.4 =
add redirect_to parameter

= 2.2.3 =
typo

= 2.2.2 =
better notation

= 2.2.1 =
keep ssl connection except for guzzle

= 2.2.0 =
fix notice

= 2.1.9 =
some issue fixed around another

= 2.1.8 =
force update by another content

= 2.1.7 =
disable has_archive() on default built-in custom post type

= 2.1.6 =
fix js error

= 2.1.5 =
keep sticky when using quick edit

= 2.1.4 =
add explanation to contact form 7 list

= 2.1.3 =
improve search quality

= 2.1.2 =
add force index

= 2.1.1 =
fix some spec

= 2.1.0 =
auto update

= 2.0.9 =
minor bugs

= 2.0.8 =
add line break to paclic form mail text

= 2.0.7 =
delete debug code

= 2.0.6 =
fix some bugs

= 2.0.5 =
fix filename2

= 2.0.4 =
fix filename

= 2.0.3 =
better public form and fix hook

= 2.0.2 =
unseen contents add link

= 2.0.1 =
unseen contents add nowrap

= 2.0.0 =
improve unseen contents

= 1.9.9 =
unseen contents

= 1.9.8 =
improve search sitemap.xml

= 1.9.7 =
replace save dir

= 1.9.6 =
fix save hook bug

= 1.9.5 =
add rule of banned custom_fields' name

= 1.9.4 =
fix restrict_manage_posts

= 1.9.3 =
new Google Analytics code - typo

= 1.9.2 =
new Google Analytics code

= 1.9.1 =
fix order of duplicatable fields

= 1.9.0 =
fix validations

= 1.8.9 =
add new filters

= 1.8.8 =
hide terrible error message from non administrator

= 1.8.7 =
better dashboard information

= 1.8.6 =
fix slow query

= 1.8.5 =
fix sticky bug

= 1.8.4 =
display mail form information

= 1.8.3 =
namespace

= 1.8.2 =
fix Google Map, Notation.

= 1.8.1 =
fix js

= 1.8.0 =
add is_array() to notation

= 1.7.9 =
fix tiny errors

= 1.7.8 =
fix tiny warning

= 1.7.7 =
fix nonce and label

= 1.7.6 =
fix PHP_SESSION_DISABLED issue

= 1.7.5 =
improve public form

= 1.7.4 =
force avoid author page

= 1.7.3 =
refactoring and add new checks

= 1.7.2 =
fix help text

= 1.7.1 =
some Debugs

= 1.7.0 =
add public form and environmental check

= 1.6.9 =
small fix

= 1.6.8 =
add dashi_sitemap

= 1.6.7 =
small fix

= 1.6.6 =
fix duplicate custom_fields

= 1.6.5 =
add shortcode of is_user_logged_in()

= 1.6.4 =
add duplicate custom_fields

= 1.6.3 =
add dashi_mod_custom_fields Hook

= 1.6.2 =
add is_sticky_admin_only

= 1.6.1 =
care for child theme

= 1.5.9 =
zip code (thx https://github.com/ninton/jquery.jpostal.js)

= 1.5.8 =
improve fields appearance

= 1.5.7 =
add required text

= 1.4.8 =
rename plugin

= 1.4.7 =
improve search

= 1.4.6 =
message for failure to replacing another version

= 1.4.5 =
remove bad word

= 1.4.4 =
lower php compatibly 2
php 5.3 seems cannot recognize namespaced class_alias()

= 1.4.3 =
lower php compatibly

= 1.4.2 =
fix NG plugin name

= 1.4.1 =
better another post

= 1.4.0 =
maintenance

= 1.3.9 =
another post

= 1.3.8 =
fix revision and preview of custome fields 2

= 1.3.7 =
fix revision and preview of custome fields

= 1.3.6 =
revision and preview of custome fields

= 1.3.5 =
WYSIWYG

= 1.3.4 =
maintenance

= 1.3.2 =
default values od posttype

= 1.3.1 =
typo

= 1.3 =
better form

= 1.2 =
add sticky

= 1.1 =
fix search results

= 1.0 =
improve search results

= 0.9 =
some fixes

= 0.8 =
add mail form

= 0.5 =
first commit.

== Upgrade Notice ==

= 3.4.15 =
Security update for search, previews, copying, sitemap output and public uploads. Review legacy pending attachments before updating. Files selected in existing forms may need to be uploaded again.

== Frequently Asked Questions ==

= What database storage do public form attachments require? =

Submitting a public form with database-backed attachments requires InnoDB for the WordPress posts and postmeta tables and the Dashi upload table. Dashi uses its own database transaction to confirm attachment references and transfer ownership together. Integrations must finish any open database transaction before submitting a public form.

= How do postal lookup limits work behind a reverse proxy? =

Configure the web server to restore the visitor IP in `REMOTE_ADDR` when possible. Otherwise, define `DASHI_POSTAL_TRUSTED_PROXIES` in `wp-config.php` as an array of exact proxy IP addresses. Configure those proxies to overwrite `X-Dashi-Client-IP` with a single verified visitor IP for every request and strip any client-provided value. The postal limiter accepts that header only from a listed proxy. Public IPv6 fetches are limited by /64. The default trusts the web server's `REMOTE_ADDR`.


= How do I invalidate crawl snapshots when changing WP_HOME? =

When changing or restoring `WP_HOME` in `wp-config.php`, set `DASHI_CRAWL_CONFIG_EPOCH` to a new value in the same deployment, for example `define('DASHI_CRAWL_CONFIG_EPOCH', '2026-11-22-1');`. Use a value that has never been used for that site, even when rolling back the URL. This prevents reuse of previous snapshots even if WordPress could not record the URL change in the database. Snapshots become searchable again after a fresh crawl.

not yet.
