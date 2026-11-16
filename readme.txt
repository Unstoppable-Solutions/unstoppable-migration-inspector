=== Unstoppable Migration Inspector ===
Contributors: paramedic192
Tags: migration, site health, mixed content, broken images, search replace
Requires at least: 6.5
Tested up to: 7.1
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Checks a WordPress site after it has been moved and explains what did not transfer correctly. Read-only by default.

== Description ==

You moved your WordPress site to a new host, server or domain. Did everything arrive? Unstoppable Migration Inspector answers that question.

**This is a development build (0.1.0).** It contains the dashboard, settings and migration snapshots. The scanner, repair tools and reports are being built in stages and are not included yet. Nothing below the "Planned" heading works in this build.

= Included in this build =

* A Migration Health dashboard that shows each inspection category as Passed, Warning, Critical, Not Tested or Not Applicable. The plugin never calls a site healthy just because some checks passed.
* **Migration snapshots.** Before you move a site, take a snapshot. After the move, compare it with the new site. The comparison lists the exact IDs of posts, pages, media, users, comments, terms and WooCommerce orders that are missing, separately from records that changed and records created after the snapshot. Snapshots can be exported as a file and imported on the new site. They contain IDs, statuses, dates, sizes and settings only, never names, email addresses, content or order details.
* WooCommerce orders are compared whether the store uses High-Performance Order Storage (HPOS) or the older posts storage, including after a switch between the two.
* Settings for the old domains your site used, scan speed limits for shared hosting, and data retention.
* Your data stays yours: deleting the plugin keeps its snapshots and scan history unless you choose to remove them.

= Planned for version 1.0 =

* **Scan Website:** a read-only inspection of domains and URLs, the database, media files, HTTPS and mixed content, WordPress configuration and, when it is active, WooCommerce.
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

= Does a snapshot contain customer data? =

No. It stores record IDs, status names, dates, content sizes in bytes, user roles and site settings such as the theme and plugin list. It never stores names, email addresses, logins, post or page content, order totals, addresses or payment details.

= Can a snapshot find missing WooCommerce orders? =

Yes, by order ID, as long as the snapshot was taken on the old site before the move. It works with HPOS and with the older posts storage. Orders placed after the snapshot are listed separately as created after the snapshot.

= Does it replace my backup or migration plugin? =

No. It works alongside tools like Duplicator, All-in-One WP Migration or your host's migration service, and checks whether the move worked.

= What happens when I delete the plugin? =

By default it keeps its data (settings, snapshots and scan history), so a reinstall picks up where you left off. To remove everything when deleting the plugin, switch on "Remove all Migration Inspector data when the plugin is deleted" under Migration Inspector > Settings > Data & Privacy first. Deactivating the plugin never removes anything.

== External Services ==

This plugin does not connect to any external service and sends no data off your site.

== Privacy ==

The plugin stores its settings in one WordPress option (`umin_settings`) and creates three database tables for its own snapshots, scan history and findings. It does not collect personal data about visitors or customers. Plugin data is kept when the plugin is deleted, unless you switch on complete removal in the settings.

== Changelog ==

= 0.1.0 =
* Development build: plugin foundation, Migration Health dashboard, tabbed settings that save each tab independently, database tables, REST status route and uninstall routine.

== Upgrade Notice ==

= 0.1.0 =
Development build. Not for production use.
