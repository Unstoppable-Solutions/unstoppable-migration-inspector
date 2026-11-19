=== Unstoppable Migration Inspector ===
Contributors: paramedic192
Tags: migration, site health, mixed content, broken images, search replace
Requires at least: 6.5
Tested up to: 7.1
Stable tag: 0.3.1
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Checks a WordPress site after it has been moved and explains what did not transfer correctly. Read-only by default.

== Description ==

You moved your WordPress site to a new host, server or domain. Did everything arrive? Unstoppable Migration Inspector answers that question.

**This is a development build (0.3.1).** It contains the dashboard, the read-only scanner, migration snapshots and settings. Repair tools and reports are being built in stages and are not included yet. Nothing below the "Planned" heading works in this build.

= Included in this build =

* A Migration Health dashboard that shows each inspection category as Passed, Warning, Critical, Not Tested or Not Applicable. The plugin never calls a site healthy just because some checks passed.
* **Scan Website:** a read-only inspection of your site after a move. It looks for links and settings that still use the old domain, a staging site or a temporary host address (including inside page-builder data and serialized settings), HTTPS and mixed-content problems, missing database tables, table prefix problems that leave users without roles, Media Library files that did not arrive, broken links to uploads, permalink and .htaccess problems, missing theme or plugin files, overdue scheduled tasks, risky cache and debug settings, search engines being blocked, and, when WooCommerce is active, store pages, HPOS order storage and "coming soon" mode.
* Every finding has a severity (Critical, Warning or Notice), an explanation of why it matters, a recommended next step and the exact items affected. Large sites are scanned in small steps, and an interrupted scan resumes where it stopped.
* **Migration snapshots.** Before you move a site, take a snapshot. After the move, compare it with the new site. The comparison lists the exact IDs of posts, pages, media, users, comments, terms and WooCommerce orders from the snapshot that cannot be found on the new site, separately from records that changed (including content changes that keep the same size) and records created after the snapshot. Snapshots can be exported as a file and imported on the new site. They contain IDs, statuses, dates, sizes, a short content fingerprint per post and settings, never names, email addresses, content or order details.
* WooCommerce orders are compared whether the store uses High-Performance Order Storage (HPOS) or the older posts storage, including after a switch between the two.
* Settings for the old domains your site used, scan speed limits for shared hosting, and data retention.
* Your data stays yours: deleting the plugin keeps its snapshots and scan history unless you choose to remove them.

= Planned for version 1.0 =

* **Repair Center:** a plain explanation of each problem, why it matters and how to fix it. Where a fix can be done safely, such as a carefully limited database replacement, the plugin offers it with a preview, confirmation and an undo log.
* **Reports:** downloadable CSV, JSON and printable reports.

= How it is built =

* Read-only unless you explicitly start a repair.
* No account, subscription or paid upgrade. Every feature is included.
* No tracking and no connections to outside services.
* Works on shared hosting, single sites and multisite networks.
* Does not need any other Unstoppable Solutions plugin.

== Installation ==

1. In your WordPress admin go to Plugins > Add New, search for "Unstoppable Migration Inspector", then click Install and Activate. Or upload the plugin folder to `/wp-content/plugins/` and activate it on the Plugins screen.
2. Open **Migration Inspector** in the admin menu.
3. Optional: under Migration Inspector > Settings, enter the domains your site used before it was moved.

== Frequently Asked Questions ==

= Will this plugin change my site? =

Not unless you ask it to. Scans are read-only. Any repair that changes data must be started by you, shows a preview first and asks for confirmation. Repairs are not part of this development build.

= Does it send my data anywhere? =

No. The plugin does not contact any outside service and does not collect usage statistics.

= Who can use it? =

Administrators. On multisite, only super admins will be able to run anything that changes the database. A site owner can switch off all database changes by adding `define( 'UMIN_DISABLE_WRITES', true );` to wp-config.php.

= Is a snapshot a backup? =

No. A snapshot records which records existed and a few facts about each (status, dates, size), not their content. It can tell you exactly what is missing after a move, but it cannot restore it. Keep a real backup of the old site until you have checked the new one.

= Does the scan change anything? =

No. The scan only reads your site's database, settings and files. The only data it writes is its own results, stored in the plugin's own tables. You can delete old scan results from the Scan Results screen.

= How does the scan know my old domain? =

Enter it under Migration Inspector > Settings > General > Previous domains. The scan also detects likely old addresses: where a migration snapshot was taken, the address remembered in your posts' internal IDs (GUIDs), and staging or temporary addresses linked from recent content. Detected addresses can include sites that are not yours, such as demo content, so check the listed items before changing anything.

= Does a snapshot contain customer data? =

No. It stores record IDs, status names, dates, content sizes in bytes, a short content fingerprint per post (see the next question), user roles and site settings such as the theme and plugin list. It never stores names, email addresses, logins, post or page content, order totals, addresses or payment details.

= Can a snapshot find missing WooCommerce orders? =

It finds orders whose IDs existed in the snapshot but cannot be found on the new site, as long as the snapshot was taken on the old site before the move. It works with HPOS and with the older posts storage. Orders placed after the snapshot are listed separately. An order that is "not found" is not automatically a lost order: it may have been deleted on purpose after the snapshot. Check each one against the old site or a backup before treating it as lost.

= Can a snapshot file reveal my content? =

It does not contain your content, but each post has a short content fingerprint so that edits keeping the same size can be detected. The file includes what is needed to check those fingerprints, so anyone holding it could test a guess at a post's content, and very short, predictable content (a PIN or short code in a private post, for example) could be worked out together with the stored size. Treat exported snapshot files as confidential. If you prefer, use "Export Without Content Fingerprints": the comparison then still detects missing records and size changes, but not edits that keep the same size.

= What if I compare a snapshot from a different site? =

The plugin checks whether the snapshot belongs to this site before comparing: it looks at a site key that travels with the database, the site name, address and table prefix, and whether a sample of the snapshot's record IDs exist here. If it cannot confirm the match, it explains why and asks you to confirm before comparing, and the results are clearly marked as not matched.

= Does it replace my backup or migration plugin? =

No. It works alongside tools like Duplicator, All-in-One WP Migration or your host's migration service, and checks whether the move worked.

= What happens when I delete the plugin? =

By default it keeps its data (settings, snapshots and scan history), so a reinstall picks up where you left off. To remove everything when deleting the plugin, switch on "Remove all Migration Inspector data when the plugin is deleted" under Migration Inspector > Settings > Data & Privacy first. Deactivating the plugin never removes anything.

== External Services ==

This plugin does not connect to any external service and sends no data off your site.

== Privacy ==

The plugin stores its settings in one WordPress option (`umin_settings`) and creates its own database tables for snapshots, comparisons, job progress, scan history and findings. Snapshots contain record IDs, status names, dates, content sizes, a short salted content fingerprint per post, user roles and site settings, never names, email addresses, content or order details. Exported snapshot files should be treated as confidential (see the FAQ). It does not collect personal data about visitors or customers. Plugin data is kept when the plugin is deleted, unless you switch on complete removal in the settings.

== Changelog ==

= 0.3.1 =
* Clearer comparison wording when records were deleted: the identity note points to the Not found list, and tables that shrank with those records are marked as consistent with them.

= 0.3.0 =
* Development build (Phase 2b).
* Read-only scanner: Scan Website on the dashboard, a Scan Results screen with severity, explanation, next step and affected items for each finding, and resumable scanning for large sites.

= 0.2.0 =
* Development build (Phase 2a).
* Migration snapshots: capture, compare (not found, changed, created after snapshot, not in snapshot), secure export and import, WooCommerce HPOS and legacy order storage.
* Snapshot identity check, content fingerprints, and an export option without them.
* Clearer comparison results on staging copies (table prefix, backup and staging tool tables).

= 0.1.0 =
* Development build: plugin foundation, Migration Health dashboard, tabbed settings that save each tab independently, database tables, REST status route and uninstall routine.

== Upgrade Notice ==

= 0.3.1 =
Development build. Not for production use.
