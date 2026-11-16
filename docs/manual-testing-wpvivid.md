# Manual Testing with WPvivid Staging Pro

**Purpose:** test Migration Inspector on realistic migrations, clean and deliberately damaged, using WPvivid staging copies on the same host. No host switch is needed and production is never put at risk.

**Plugin build:** Phase 2b (snapshots plus the read-only scanner). Database repair is not in this build.

**WPvivid reference:** the WPvivid Staging Pro documentation as of February 2026. Menu names may differ slightly in your version. I have not run WPvivid myself. Every "expected result" below comes from how Migration Inspector works and is covered by its automated tests. Record what you actually see.

---

## 0. Safety rules (read first)

1. **Never click "Copy the Staging Site to Live" (push to live)** in these tests. Every scenario only creates, inspects and deletes staging copies. A push overwrites the live site.
2. **Back up the live site with WPvivid first**, and keep the backup until testing is finished.
3. **Use a site you can afford to experiment on.** A low-traffic or internal site is ideal. Avoid your busiest store.
4. **For the damaged scenarios, put the staging copy in a separate database:** "Use a Separate Database" when creating the staging site. With "Share Live Database", staging and live tables sit side by side with different prefixes, and a manual database change typed with the wrong prefix would hit the live site.
5. **WooCommerce staging copies hold real customer data and can act on it.** Opening the staging admin runs scheduled tasks: emails, subscription renewals, webhooks. Before opening a WooCommerce staging copy:
   - switch payment gateways to test or sandbox mode on the staging copy (WooCommerce Subscriptions normally detects a duplicate site and pauses renewals; check that it did), and
   - block outgoing email on the staging copy (an SMTP plugin's "disable email" option, or a mail-catching plugin).

   Migration Inspector itself sends nothing and changes nothing, but WordPress and WooCommerce run their own tasks whenever the site is visited.
6. **Damage only the staging copy.** In this guide, "damage" means changes made through the staging site's own admin screens, its file manager folder (`/staging01/…`) or its own tables. Check the address bar and folder path before each change.
7. **Delete each staging copy when you finish a scenario** (WPvivid > Staging Sites > Delete). That removes the staging folder and its tables.

---

## 1. Preparation (once)

| Step | What to do | Record |
|---|---|---|
| 1.1 | Note the versions of WordPress, PHP, MySQL/MariaDB, WooCommerce (if used) and WPvivid Staging Pro, and the host. | Versions |
| 1.2 | Back up the live site with WPvivid. | Backup name |
| 1.3 | On live: Plugins > Add New > Upload, and install the Migration Inspector ZIP from the Phase 2b branch. Activate it. | — |
| 1.4 | On live: Migration Inspector > Settings > General. Leave "Previous domains" empty for now. | — |
| 1.5 | On live: Migration Inspector > Dashboard > **Scan Website**. Keep the tab open until the results open. | Overall status, counts per category, scan time |
| 1.6 | Save the live baseline (screenshots of the results). It is the reference for "live was not affected" at the end. | Screenshots |
| 1.7 | On live: Migration Inspector > Snapshots > **Take Snapshot**, labelled "Before staging tests". | Record count, time taken |
| 1.8 | Export the snapshot twice: **Export** and **Export Without Content Fingerprints**. Keep both files. | File sizes |

**Check the baseline:** a healthy live site should show no Critical items. Write down every Warning and Notice. They are expected to reappear on the staging copies, and you will want to tell them apart from problems the copy introduced.

---

## 2. Scenarios

Each scenario lists how to create the staging copy, what to damage, the steps in Migration Inspector, and the expected results. "Staging" always means the WPvivid staging copy (for example `https://example.com/staging01` or `https://dev.example.com`).

### S1. Clean copy, snapshot travels with the database

*A perfect migration. Confirms there are no false alarms.*

1. WPvivid > Staging Sites > Create A Staging Site.
   - Directory or subdomain: your choice; note it.
   - Database: either option.
   - **Database Will Be Copied:** everything (default).
   - **Files:** everything.
   - Create Now and wait for it to finish.
2. Log in to staging (same credentials as live).
3. Migration Inspector > Snapshots. The "Before staging tests" snapshot is listed, because it came across with the database. Click **Compare with This Site**.
4. Migration Inspector > Dashboard > **Scan Website**.

**Expected:**
- **Comparison, identity:** "Snapshot matched to this site", with "Differences noted" listing the different site address. If the staging copy uses a different table prefix (shared database), the prefix is listed too.
- **Comparison, records:** no records Not found. Anything created on live after the snapshot and before the copy is listed under "created after the snapshot". Comments, sessions or orders added on live in between show the same way.
- **Scan:** the same items as the live baseline, plus these expected differences on a staging copy:
  - **Search engines are asked not to index this site** (Warning). WPvivid switches this on for staging, which is correct for a copy. The warning is there to catch the setting when a copy goes live. On a subdomain whose name looks like staging (`dev.`, `staging.`…) the scan recognises the copy and stays quiet.
  - **Old addresses:** "Addresses searched for" lists the live address (detected from post GUIDs). If WPvivid replaced every live URL, no old-address findings appear. Any that do appear show exactly where WPvivid left the live address behind. Note them; they are real findings.
  - **The database also holds tables with other prefixes** (Notice), with a shared database only. Those are the live tables.

### S2. Snapshot moved by file (database rebuilt without the plugin's tables)

*Tests export/import, and identity checks when the plugin's data does not travel.*

1. Create a staging site. Under **Database Will Be Copied**, deselect every table whose name contains `umin_` (seven tables). Copy everything else.
2. On staging: Migration Inspector > Snapshots. The list is empty. **Import a snapshot from another site**: choose the file from step 1.8 (normal export) and click Import.
3. **Compare with This Site.**
4. Repeat with the "Without Content Fingerprints" file.

**Expected:**
- Both imports succeed.
- The identity is "matched". The site key lives in the options table, which was copied.
- The results match S1.
- With the fingerprint-free file, the comparison still finds Not found and changed records, but cannot report content edits that kept the same size. The screen and readme say so.
- Try importing a non-snapshot file, for example a `.txt` renamed to `.json`. It is refused with a clear message, and nothing is stored.

### S3. Partial database (tables left out)

*The most common real-world damage: an import that stopped part-way or skipped tables.*

1. Create a staging site with **Use a Separate Database**. Under **Database Will Be Copied**, deselect:
   - `{prefix}comments` and `{prefix}commentmeta`
   - WooCommerce stores with HPOS on: `{prefix}wc_orders`, `{prefix}wc_order_addresses`, `{prefix}wc_order_operational_data`, `{prefix}wc_orders_meta`
   - WooCommerce stores without HPOS: `{prefix}woocommerce_order_items` and `{prefix}woocommerce_order_itemmeta`

   Keep the `umin_` tables so the snapshot travels.
2. On staging: compare the snapshot, then scan.

**Expected:**
- **Comparison:** every comment ID from the snapshot is Not found. Orders are Not found as well, if the order table itself was left out. The orders section shows the caution that Not found is not the same as lost. The identity is still "matched".
- **Scan (Database):** **Core WordPress tables are missing** (Critical), listing the comments tables.
- **Scan (WooCommerce, HPOS on):** **High-Performance Order Storage is on, but its tables are missing** (Critical), listing each missing table.
- **Scan (WooCommerce, without HPOS):** **WooCommerce tables are missing** (Critical) for the order item tables.
- **Overall Migration Health:** Critical.
- The staging copy may show WordPress database errors on comment screens. That is expected with tables missing.

### S4. Missing media files

*Database complete, but files not copied.*

1. Create a staging site. Under **Exclude Additional Files/Folders**, exclude one year folder inside `/uploads` (for example `/uploads/2024`). Pick a year with a few dozen items. Copy the database completely.
2. On staging: compare the snapshot, then scan.

**Expected:**
- **Comparison:** nothing Not found. The database is complete. This shows the difference between the snapshot (database records) and the scanner (files).
- **Scan (Media):**
  - **Media Library items whose file is missing** (Warning): one row per attachment from that year, each linking to the media item.
  - **Content links to uploaded files that are missing** (Warning): the posts and pages that use those files, with the file names listed.
- If the site uses a media offload plugin, the scan shows **Media appears to be offloaded**, and missing files are shown as Notices instead.

### S5. Old addresses left behind

*URL replacement that missed some places.*

1. Create a clean staging site (as in S1).
2. On staging, through the staging admin only, add test content containing the **live** address:
   - **A new post** with a link and an image, where `LIVE` is your live domain:

     ```html
     <a href="https://LIVE/about/">About</a>
     <img src="https://LIVE/wp-content/uploads/2024/01/any-existing-image.jpg">
     ```

   - **A Custom HTML widget or block in a footer** with `https://LIVE/contact/`.
   - **A decoy post** with links that are not your site, to test false positives: `https://LIVE.au/` (if your domain is `LIVE`), `https://partner-site.com/`.
   - **A staging-looking link:** `https://staging.LIVE/test/`.
3. Migration Inspector > Settings > General > Previous domains: add the live address, one per line (`https://LIVE`). Save. This marks the address "confirmed".
4. Scan.

**Expected:**
- **"Addresses searched for"** shows the live address as "entered in Settings" and `staging.LIVE` as a staging-looking address.
- **Old addresses in posts, pages and other content** (Warning) lists the new post and the staging-link post, with an excerpt around each match. The decoy post is **not** listed.
- **Old addresses in site settings and widgets** lists the widget's option (for example `widget_custom_html` or `widget_block`).
- A **subdirectory staging copy** (`LIVE/staging01`) behaves correctly. Links to `LIVE/staging01/...` belong to the staging site and are never reported. Links to `LIVE/...` outside `/staging01` are reported.
- Delete the test content afterwards, or delete the staging copy.

### S6. HTTPS and mixed content

*Only on a staging copy that uses https://.*

1. On staging, create a post containing:

   ```html
   <img src="http://example.org/picture.jpg">
   <a href="http://example.org/">a plain link</a>
   <img src="http://STAGING-HOST/wp-content/uploads/2024/01/existing.jpg">
   ```

   Replace `STAGING-HOST` with the staging copy's own host.
2. Scan.

**Expected:**
- **Content loads files from other sites over http://** (Warning) lists the post, naming `example.org`. The plain link alone would not be reported.
- **Insecure links to this site in posts, pages and other content** (Warning) lists the post for the `http://STAGING-HOST` image.
- **On a staging copy served over http://:** the HTTPS category shows **The site does not use HTTPS** (Notice), and the mixed-content checks are listed as Not applicable, with the reason.

### S7. WordPress configuration damage

1. On staging:
   - **Settings > Permalinks:** note the structure. If it is pretty (`/%postname%/`), leave it. If it is plain, choose "Post name" and save.
   - **File manager:** in `staging01/wp-content/plugins/` (check the path!), rename one small, non-essential active plugin's folder, for example `hello-dolly` → `hello-dolly-off`. Do not visit the Plugins screen yet: WordPress deactivates missing plugins when it loads.
   - **On a subdirectory staging copy using Apache/LiteSpeed:** open `staging01/.htaccess` and note its `RewriteBase` line. Do not change it unless you want to test that finding (see below).
2. Scan.

**Expected:**
- **Active plugins whose files are missing** (Warning) names the renamed plugin.
- **Optional `.htaccess` test:** change `RewriteBase /staging01/` to `RewriteBase /` on staging, then scan. Expect **.htaccess points to a different folder** (Warning). Restore it afterwards. This is the classic "moved into a subfolder" mistake.
- Rename the plugin folder back afterwards.

### S8. WooCommerce configuration

*Store sites only. Follow safety rule 5 first.*

1. On staging:
   - WooCommerce > Settings > Advanced > Page setup: set **Checkout page** to none (or to an unrelated page). Save.
   - WooCommerce > Settings > Site visibility: choose **Coming soon**. Save.
2. Scan.

**Expected:**
- **The cart or checkout page is missing or not set up** (Critical).
- **The store is in "coming soon" mode** (Warning). On a subdomain that looks like staging (`dev.`/`staging.`), this warning is deliberately not shown.
- The WooCommerce category is Critical.
- If WPvivid skipped `wc_product_meta_lookup` or `wc_order_stats`, expect the **Product lookup data is incomplete** or **Store analytics are missing past orders** notices.

### S9. Interrupted and resumed scan

1. On any staging copy, start **Scan Website**.
2. When the progress bar is about a third of the way, close the browser tab.
3. Reopen Migration Inspector > Scan Results.

**Expected:**
- The scan shows "This scan has not finished", with **Resume Scan**.
- Resume completes the scan. The results equal a fresh uninterrupted scan; check the totals.
- Starting **Scan Website** while a scan is unfinished resumes it instead of starting a second one.
- Within about a minute of closing the tab, Resume may say the job is "already running in another window". Wait a minute and try again. This lock prevents two windows from processing the same batch.

### S10. Snapshot from the wrong site

1. Take and export a snapshot on a **different** site you own, or on a fresh WordPress install on the same host.
2. On a staging copy: import it and click **Compare with This Site**.

**Expected:**
- A confirmation dialog: "This snapshot appears to come from a different site", listing the reasons.
- Cancelling starts nothing. Confirming runs the comparison, and the result is marked "Snapshot not matched to this site".

### S11. Old staging copy that has drifted

*Checks that a matching site key does not hide a mismatch.*

The identity check samples up to 50 IDs each of posts, pages, media, products, users and orders from the snapshot, and checks how many still exist.

1. Create a staging copy (as in S1). On staging, permanently delete most posts and pages, plus products and orders on a store: move them to Trash, then empty the Trash.
2. Compare the "Before staging tests" snapshot on that staging copy.

**Expected, depending on how much of the sample is gone:**
- **Fewer than half of the sampled records remain:** the comparison asks for confirmation even though the site key matches ("The site key matches … but most sampled records are not here"). After confirming, the deleted items are listed as Not found.
- **Half or more remain** (for example because media and users were kept): "Snapshot matched to this site", with the overlap shown under "Differences noted" ("Only N% of a sample of record IDs…"), and the deleted items listed as Not found.

Do not delete media on a staging copy for this test. It is not needed, and file deletions are harder to undo.

### S12. Read-only proof

Run on any staging copy:

1. Take a snapshot.
2. Run **Scan Website** twice.
3. Compare the snapshot.

**Expected:** no records under Not found, Changed or "created after the snapshot", other than anything you or WordPress created in the meantime (for example a scheduled post going live). The scanner changed nothing.

---

## 3. Finishing

1. Delete every staging copy: WPvivid > Staging Sites > Delete.
2. On live:
   - Run **Scan Website** again and compare with the baseline from step 1.6. It should match.
   - Snapshots > compare "Before staging tests" with the live site. Only records created on live since then should appear.
   - With a shared database, the **other prefixes** notice disappears once the staging tables are deleted.
3. Keep or remove Migration Inspector on live. Deleting it keeps its data unless "Remove all data" is switched on under Settings > Data & Privacy.

---

## 4. Recording results

Copy this table for each scenario:

| Scenario | Staging type (subdir/subdomain, shared/separate DB) | Expected | Observed | Pass/Fail | Notes or screenshots |
|---|---|---|---|---|---|
| S1 | | | | | |

Report also:
- any finding you believe is **wrong** (a false alarm), with the item it points to
- any wording that was **unclear**, or a next step you could not follow
- the scan time and the site size (posts, media items, orders)
- any PHP warnings in `wp-content/debug.log` (switch on `WP_DEBUG_LOG` on staging only) that mention `unstoppable-migration-inspector`

---

## 5. What this guide does not cover

- **Pushing staging to live.** Excluded on purpose (see safety rule 1).
- **Repairs.** Search & Replace and other repairs arrive in Phase 3, after the scanner has passed its tests.
- **Moves between hosts.** The staging copies simulate the database and file outcomes of a move on the same server. Host-level differences are not simulated: PHP version, server software and file ownership. The scanner's uploads-writable, `.htaccess` and cron checks behave the same on a real new host.
