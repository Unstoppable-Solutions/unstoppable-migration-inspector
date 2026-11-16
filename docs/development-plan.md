# Unstoppable Migration Inspector: Development Plan

**Status:** Direction approved by the owner on 16 Nov 2026 (revision 2), Phase 1 in progress
**Prepared:** 16 November 2026
**Applies to:** the free WordPress.org plugin `unstoppable-migration-inspector`

This plan follows Section 17 of the project brief. It records the research, the
architecture we chose and why, what v1.0 will and will not do, the main risks,
and the phased milestones.

---

## 1. Research summary

### 1.1 Better Search Replace (source reviewed at commit `a259ebd`, v1.4.11)

What it does well:

- Small, readable code base built on `$wpdb`, with no bundled backup engine in
  the free build.
- Serialized values are unserialized with `allowed_classes => false`, so stored
  objects cannot run code during a replacement (a 2024 security fix).
- Values are only unserialized when the search string is present, which keeps
  large tables fast.
- GUIDs are skipped unless the user opts in.
- `siteurl` is changed last, so the admin session survives the run.
- Multisite: subsite admins only see their own site's tables.
- Table and column names go through the `%i` identifier placeholder.

Gaps we can address without copying anything:

| Area | What BSR does | Consequence |
|---|---|---|
| Paging | `SELECT * ... LIMIT offset, size` with no `ORDER BY` | Row order is not guaranteed, so rows can be skipped or read twice, and deep offsets slow down on big tables |
| Tables without a primary key | Skipped | The skip is easy to miss in the results |
| Table discovery | `SHOW TABLES` on single sites and the main site | Lists every table in the database, including other apps that share it |
| JSON-escaped URLs | Not handled | `https:\/\/old.test` (how Elementor, block attributes and many builders store URLs) is not matched by a plain `https://old.test` search |
| URL-encoded URLs | Not handled | `https%3A%2F%2Fold.test` in query strings is missed |
| Job state | Driven by the browser tab; results in a transient | Closing the tab stops the job, with no way to resume |
| Undo | Free build: none. Pro: full database backup | No targeted way to reverse one run |
| Change preview | Pro only | Free users run "blind" apart from counts |
| Diagnostics | None; it is a search-and-replace tool only | No way to tell whether a migration actually worked |

**Licence:** Better Search Replace is **GPL-3.0** (it derives from
interconnect/it's Search Replace DB, also GPLv3). Our plugin is
**GPL-2.0-or-later**. Copying BSR code would force the combined plugin to
GPLv3. WordPress.org would accept that, but it would quietly change our
declared licence, which is not acceptable. **Decision: no BSR code is copied.**
We studied it as a reference only. Our replacement engine is written
independently, on top of WordPress core functions (`is_serialized()` and
related), which are GPLv2-or-later.

### 1.2 Other plugins in the space

| Plugin | What it covers | Gap relevant to us |
|---|---|---|
| Better Search Replace | Search and replace, dry run, multisite | No diagnostics; change preview and backup are Pro |
| Go Live Update URLs | One-click domain change | Serialized and JSON data only in core tables on the free version; other tables need Pro |
| WP Migrate Lite | Database export, find and replace, backup | A migration tool; the exact change preview is Pro |
| Search & Replace (Syde, later WP Media) | Search and replace, backup | Marked unmaintained; untested on the last 3 WordPress majors |
| Really Simple Security | HTTPS setup; a runtime mixed-content *fixer* | The full mixed-content *scan* is Pro only |
| Broken Link Checker and similar | Ongoing link monitoring | Not focused on migrations; recurring scans |
| Post Migration Checklist | A checklist of config and plugin status | Untested on the last 3 WordPress majors; no repair workflow |
| MigrateFix | Old-URL dry run and URL replacement, a few launch checks | Fewer than 10 installs; no media, mixed-content or report features |
| 1Gbits Site Move Inspector | **Pre**-migration hosting readiness | Read-only and runs before the move, so it complements us rather than overlapping |

The name "Unstoppable Migration Inspector" is not in use. The closest name is
"1Gbits Site Move Inspector", which is a different name for a pre-migration
tool, so there is no confusing overlap.

### 1.3 What we offer that is not available elsewhere for free

These are specific and checkable, as Section 16 of the submission guide asks.
Nothing here claims "better" or "more powerful".

1. **One read-only post-migration inspection** across URLs, database, media,
   HTTPS/mixed content, configuration and (when active) WooCommerce/HPOS, with
   each finding explained in plain language.
2. **Free per-change preview.** The dry run lists the exact table, row, column
   and before/after snippet, not just counts.
3. **JSON-escaped and URL-encoded variants** of a URL are searched and replaced
   together with the plain form, so page-builder data is not left behind.
4. **Row-level undo log for every replacement run.** Only the original values of
   rows that actually changed are stored, so one run can be reversed without a
   full database backup. This suits shared hosting.
5. **Resumable, server-side job state.** A run survives a closed tab, a timeout
   or a PHP fatal, and resumes from the last confirmed primary key.
6. **Verify loop:** rescan after a repair, and export a before/after report
   (CSV, JSON, printable HTML).
7. **Migration comparison snapshot.** Before the move, capture counts and
   configuration fingerprints. After the move, compare against the live
   destination. This is the only reliable way to say "312 orders and 41
   attachments that existed before the move are missing", which inspecting the
   destination alone cannot prove. None of the plugins reviewed offer this.
8. **No outbound requests to any third party and no paid tier.** Nothing is
   locked.

---

## 2. Requirements we checked

- **WordPress:** 7.1 is current; "Tested up to: 7.1". WordPress 7.0 raised the
  minimum PHP version to 7.4, and 7.1 did not change it.
- **WooCommerce:** 10.8 requires WordPress 6.9. WooCommerce 11.6 (planned
  February 2027) will require PHP 8.1. HPOS is now the default for new stores.

**Chosen minimums:** `Requires at least: 6.5`, `Requires PHP: 7.4`.

- 6.5 covers the `%i` identifier placeholder (added in 6.2), current REST and
  `wp.apiFetch` behaviour, and the `Requires Plugins` header. This leaves
  room for sites that have not updated yet.
- PHP 7.4 matches the WordPress floor and reaches the most shared hosts. The
  code avoids PHP 8-only syntax, and PHPCompatibilityWP enforces that.
- We can raise either minimum later without breaking anything.

---

## 3. Architecture

### 3.1 Layout

```text
unstoppable-migration-inspector/
├── unstoppable-migration-inspector.php   bootstrap: constants, autoloader, hooks
├── uninstall.php                         removes plugin data (documented)
├── readme.txt  license.txt
├── includes/
│   ├── class-autoloader.php
│   ├── core/       Plugin, Activator, Deactivator, Capabilities, Schema, Settings
│   ├── rest/       REST_Controller (+ one controller per area as phases land)
│   ├── admin/      Admin_Menu, Dashboard_Page, Settings_Page, Assets
│   ├── scanner/    (Phase 2) Scan_Manager + one class per check category
│   ├── database/   (Phase 3) Table_Inventory, Replacer, Value_Transformer,
│   │                          Job_Runner, Undo_Log
│   └── reports/    (Phase 4) Report_Builder, Exporters
├── assets/css  assets/js
├── templates/      admin view partials (escaped output only)
├── languages/
├── tests/          (dev only, excluded from the release ZIP)
└── docs/           (dev only)
```

- **Namespace:** `UnstoppableSolutions\MigrationInspector`
- **Prefix:** `UMIN_` for constants and `umin_` for options, tables, hooks and handles
- **File names:** follow WPCS (`class-*.php`). The autoloader maps the
  namespace to lowercase folders.

### 3.1a Product principles (owner direction, 16 Nov 2026)

1. **Inspection first.** The dashboard opens with a single primary action:
   **Scan Website**. Search & Replace is not a headline feature. It is offered
   as a tool from a finding that needs it (for example "References to the
   previous domain found → Review a replacement"), and is otherwise reachable
   only as a secondary item.
2. **Useful without ever repairing.** The scanner, snapshot comparison and
   reports must deliver full value to an admin who never runs a repair.
3. **Extremely conservative writes.** When in doubt, skip and report. See §3.4.
4. **Standalone.** No dependency on Activity Tracker, Plugin Guardian, Site
   Manager or any other Unstoppable plugin. Integrations, if any, come later
   as optional hooks.

### 3.2 Key decisions

| Decision | Choice | Why |
|---|---|---|
| Admin UI | Server-rendered PHP screens plus small vanilla JS using WordPress's bundled `wp-api-fetch` | No build step, human-readable source for review, and a native WordPress look |
| Async operations | REST API (`unstoppable-migration-inspector/v1`) | Permission callbacks on every route; cookie auth with the `wp_rest` nonce |
| Long jobs | Persistent job state in the database; execution is one bounded batch per authenticated request, driven by an open admin screen. No background execution in v1.0 (see §3.5) | Works without cron or loopback requests, which many hosts block. A closed browser or a timeout leaves a paused job that resumes from its last committed cursor |
| Paging | Keyset paging on the primary key (`WHERE pk > last ORDER BY pk LIMIT n`) | Deterministic, fast on big tables, and a natural resume point |
| Concurrency | One modifying job at a time, through a lease on the job row (owner token + expiry, claimed with a conditional `UPDATE`) | Prevents two tabs, two admins or a retried request from running the same batch twice |
| Plugin data | Custom tables `umin_snapshots`, `umin_scans`, `umin_findings`, `umin_jobs`, `umin_undo` (per site) | Avoids bloating `wp_options`; queries stay indexed |
| Reports | Built on demand from stored findings and streamed through an authenticated REST route; nothing written to disk | No public files, no path exposure, nothing to secure in `uploads/` |
| Tables in scope | Only tables that start with this site's `$wpdb->prefix` (on multisite, per-site tables plus the network tables when a super admin is acting) | Never touches other apps that share the database |
| Remote checks | Loopback only, to the site's own host, with private-IP and redirect protection | Meets the brief's SSRF rule and needs no External Services entry beyond the site itself |

### 3.3 Permissions model ("admin is necessary but not sufficient")

- Viewing the plugin and running read-only scans needs `manage_options`.
- Modifying the database (replacement, undo) needs the meta capability
  `umin_modify_database`, which maps to:
  - `manage_options` on single sites
  - `manage_network_options` (super admin) on multisite
- Every modifying request also requires all of the following:
  - a completed dry run whose fingerprint matches the request
  - a typed confirmation
  - a backup acknowledgement
  - a fresh capability check at execution time
- Site owners can switch off all database writes with
  `define( 'UMIN_DISABLE_WRITES', true );`. Scans and reports keep working.
- The capability can be changed through a filter, documented for agencies.

### 3.4 Replacement engine rules (Phase 3)

The bias is to **skip and report** whenever the outcome cannot be verified.
A skipped value is visible and fixable by hand; a silently damaged
serialized value may not be noticed for weeks.

- **Single rule for serialized data: if a value cannot be transformed and
  verified safely, it is skipped and reported.** This replaces the earlier
  wording that both skipped and walked objects.
  - Serialized values containing any object or custom-serialized class
    (`O:` or `C:` tokens, found by parsing, not a substring match) are
    **always skipped** in v1.0 and listed as "needs manual review", with the
    table, row and column. There is no option to turn this on in v1.0.
  - Serialized arrays and scalars are unserialized with
    `allowed_classes => false`, walked, re-serialized and then verified:
    the result must pass `is_serialized()`, unserialize again without error,
    and be structurally identical to the expected tree. Any failure means
    skip and report.
  - Values that `unserialize()` cannot parse (already corrupt, truncated or
    double-serialized beyond one level) are skipped and reported, never
    "repaired" by recounting string lengths.
- Every changed value is re-read from the database after the `UPDATE` and
  compared byte-for-byte with what was intended. A mismatch stops the job and
  points to the undo log.
- The first release defaults to **WordPress core tables only**. Plugin tables
  must be ticked individually, with their row counts and a short explanation.
- JSON is decoded, walked and re-encoded with the same flags the original used.
  If the re-encoded form would not match the original encoding style, the value
  is skipped and reported, not forced.
- Binary columns (`BLOB`, `BINARY`, `VARBINARY`) and values that are not valid
  UTF-8 in text columns are skipped and reported.
- GUIDs are excluded by default, with an explanation.
- The plugin's own tables and options are always excluded.
- `siteurl` and `home` are written last.
- Matching is case-sensitive by default. A case-insensitive mode is available
  and multibyte-aware.
- Each row is updated by its primary key only, with the original value in the
  `WHERE` clause as well (compare-and-swap), so a row changed by someone else
  since it was read is not overwritten; it is reported as a conflict.
- Order per row: write the undo record, then the `UPDATE`, then read back
  and verify, then advance the cursor. Re-running a batch after a timeout
  changes nothing more, because rows already replaced no longer match.

### 3.5 Persistent job state versus execution

These are deliberately separate.

- **State (persistent):** each job is a row in `umin_jobs`, holding:
  - type and parameters, with a fingerprint of the approved dry run
  - status: `pending`, `running`, `paused`, `completed`, `cancelled` or `failed`
  - cursor: table, then last primary key
  - totals and errors
  - a lease (owner token and expiry)

  State is written after every committed batch.
- **Execution (transient):** work happens only inside a REST request that
  claims the lease and processes one batch within the time budget (default
  8 s, well under common 30 s limits). The admin screen issues the next
  request when the previous one returns.
- **No background execution in v1.0.** Nothing continues through WP-Cron or
  loopback requests. That is a deliberate safety choice for a tool that can
  change data: writes happen only while an administrator has the screen open.
- **Browser closed:** the lease expires (about 60 s) and the job shows as
  **Paused** on the dashboard. Any administrator with the right capability
  can resume it. The resume step re-checks the dry-run fingerprint and asks
  for confirmation again.
- **Server timeout or fatal error mid-batch:** the lease expires, rows already
  committed are recorded in the undo log, and the cursor still points at the
  last fully committed batch. On resume, rows in the interrupted batch that
  were already changed no longer match the search, so they are skipped. The
  outcome is the same as an uninterrupted run.
- Read-only scans use the same machinery without the write steps, and also
  resume from their cursor.

### 3.6 Undo and conflict detection

- For every changed column, `umin_undo` stores:
  - job, table, primary-key values and column
  - the original value
  - a SHA-256 hash of the value the plugin wrote
- Undo runs as its own resumable job. For each record:
  - **No conflict:** the current value's hash still equals the stored hash.
    The original is restored with a compare-and-swap `UPDATE`
    (`WHERE pk = … AND col = <value we wrote>`), and the restore is verified.
  - **Conflict:** the row was edited, deleted or re-replaced after the
    original run. It is **not touched**. It is listed with the table, row,
    column and a short before/now preview, and can be exported. The admin
    resolves it by hand.
- Undo runs in reverse job order. Undoing an older job while a newer job
  touched the same cells is blocked until the newer one is undone or the
  admin explicitly excludes those cells.
- The UI and readme state plainly that **the undo log is not a backup**.
  - It only covers cells this plugin changed.
  - It cannot recover anything changed or deleted by other means.
  - Every replacement still requires a backup acknowledgement first.
- Undo records are kept until the admin deletes them or the job is older
  than the retention setting. They are never removed silently on uninstall
  (see §3.7).

### 3.7 Data retention and uninstall

- **Deleting the plugin keeps its data by default.** Snapshots, scan history,
  jobs and undo records survive an uninstall, so a reinstall can still
  compare or undo.
- Complete removal is an **explicit administrator choice**: Settings > Data &
  Privacy > "Remove all Migration Inspector data when the plugin is deleted".
  It is off by default, and the setting explains exactly what will be
  removed.
- On multisite, the choice is per site. Uninstall removes data only for sites
  that switched it on.

---

## 4. Version 1.0 scope

### In v1.0

- **Dashboard:** opens with the **Scan Website** action. Below it, the
  Migration Health summary with Passed, Warning, Critical, Not Tested and
  Not Applicable statuses. It never shows an overall "healthy" result just
  because some checks passed.
- **Migration snapshot (priority feature):**
  - *Capture* on the source site, before the move. It records:
    - counts by post type and status (including attachments and products)
    - users by role, comments, terms by taxonomy
    - WooCommerce orders by status, for both HPOS and legacy storage
    - row counts for each core and plugin table
    - active theme and plugins with versions, permalink structure, key
      settings, and upload-file totals
    - **the full list of IDs** for each tracked record type (posts by type,
      attachments, users, comments, terms, WooCommerce orders and refunds,
      products, customers' lookup rows), so missing records can be named
      exactly. A checksum alone could only say "something in this block is
      gone", which is not enough.
  - **ID list encoding** (efficient and privacy-conscious):
    - IDs are sorted and stored as ranges: `[[1, 4210], [4213, 9001], …]`.
      WordPress IDs are mostly sequential, so a site with 50,000 posts
      typically needs a few hundred ranges.
    - Long gap-heavy lists are delta-encoded and gzip-compressed (base64 in
      the JSON file). The format is versioned.
    - Each list carries its count and a SHA-256 of the canonical list, so
      corruption is detected.
    - Only integer IDs are stored: no titles, slugs, emails, names,
      addresses or order contents. Order *status* counts are kept;
      customer data is not.
    - Capture runs in batches through the job runner, so very large tables
      do not time out.
  - The comparison names missing IDs per type ("Order #10452, #10453 …").
    For WooCommerce it reads both HPOS (`wc_orders`) and legacy (`posts` with
    `shop_order`) storage. If the storage mode changed between snapshot and
    destination, it maps IDs across the two (order IDs are preserved by HPOS
    migration) and says so.
  - The snapshot is stored in the plugin's own table, so it travels with the
    database. It can also be downloaded as a JSON file and uploaded on the
    destination, for migrations that rebuild the database.
  - *Compare* on the destination: a side-by-side list of what decreased, what
    is missing and what changed, each with an explanation.
  - It contains **no personal data**: counts, IDs and configuration only, with
    no names, emails, addresses or order contents.
  - **Honest limits, stated in the UI:** it can show that records present at
    snapshot time are gone. It cannot account for records created after the
    snapshot, and it is not a backup.
  - The upload is size-limited, strictly validated against a schema, parsed
    with `json_decode` only (never unserialized) and never written to disk.
- **Read-only scanner:**
  - site URL and home URL consistency
  - old-domain, staging-domain and temporary-host references (based on the
    previous domains the admin enters, plus auto-detected candidates)
  - HTTP references on HTTPS sites, separating loaded resources (`src`, CSS
    `url()`) from harmless links
  - required core tables and prefix consistency
  - character set and collation mix
  - attachments whose files are missing, and missing generated image sizes
  - case-mismatch hints
  - upload-path mismatch
  - active theme and plugin files present
  - permalinks
  - uploads folder writable
  - WP-Cron health signals
  - key constants (values never shown)
  - WooCommerce, when active: page assignments, HPOS mode and table presence,
    and whether order sync is enabled
- **Search & Replace** (a tool reached from findings, not a headline feature):
  - table picker
  - dry run with per-change preview
  - serialized/JSON/encoding-aware replacement
  - batched and resumable jobs
  - cancel between batches
  - row-level undo
  - all safety steps from the brief (Section 6)
- **Repair Center:** each finding comes with what was detected, why it
  matters, the action, whether it can be reversed, and recovery options.
  Automated actions are limited to:
  - a prefilled search and replace
  - a rewrite-rules refresh
  Everything else is guided manual steps.
- **Reports:** CSV, JSON and printable HTML, with scan history, issue status,
  rescan after repair and configurable retention.
- **Settings:** previous domains, batch size, time budget per batch, report
  retention, and an opt-in to remove all data when the plugin is deleted.

### Not in v1.0 (kept possible by the architecture, not shown in the UI)

- Comparing against a raw SQL export or another site's live database
  (the snapshot covers the v1.0 need)
- Integrations with other Unstoppable plugins
- Scheduled health checks
- DNS and email diagnostics
- Site Manager integration
- Full database backup and restore

---

## 5. Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Corrupting serialized or JSON data | Broken widgets, builder pages, options | Round-trip verification before every write; skip and report instead of forcing; dedicated tests with nested and malformed fixtures |
| Partial write after a timeout | Half-replaced table | Each row is a single primary-key `UPDATE`, preceded by an undo-log insert; the cursor is saved after each batch; replays are idempotent |
| Admin locks themselves out by changing `siteurl` | Can't reach wp-admin | `siteurl`/`home` written last; warning when the replacement would change the current admin host; undo works through WP-CLI if needed (Phase 3) |
| Running on unrelated tables in a shared database | Damage to other apps | Prefix-scoped table inventory; non-WordPress tables never listed |
| Memory or time exhaustion on shared hosting | Fatal errors | Keyset batches with a configurable row count and a time budget (default ~10 s); stream exports |
| SSRF through URL checks | Probing internal services | Only the site's own host is allowed; DNS resolved and private/reserved ranges rejected; no redirects followed; short timeout |
| Sensitive data in findings or reports | Credentials or customer data exposed | Report builder works from an allowlist; constants report presence only; option values truncated and secrets masked; no customer or order PII |
| False positives (e.g. legitimate external HTTP links) | Users "fix" valid data | Findings carry a confidence level and context; external domains are never flagged as old domains unless the user listed them |
| WordPress.org review | Rejection or delay | Built to the October 2026 guide; Plugin Check, PHPCS and a live ZIP install test before every release |
| Unmaintained-looking duplicate of existing tools | Category-saturation review | Differentiators in §1.3 documented in the readme in plain terms |

---

## 6. Phased milestones

| Phase | Milestone | Done when |
|---|---|---|
| **1. Foundation** (this branch) | Plugin skeleton, secure admin shell, settings, schema, REST base, standards tooling, dashboard with all categories Not Tested | Activates cleanly on WordPress 7.1 with `WP_DEBUG`; PHPCS and Plugin Check clean; settings survive tab saves and reactivation |
| **2a. Migration snapshot** | Capture (batched), store, export/import, compare; WooCommerce HPOS and legacy order storage; range-encoded ID lists | See the snapshot and WooCommerce tests in §6a |
| **2b. Read-only scanner** | Scan manager with batched REST runs; URL, database, media, mixed-content, configuration and WooCommerce checks; results screen; Scan Website flow on the dashboard | Each check has fixtures and tests; a scan of a seeded "migrated" site finds the planted issues and changes no data |
| **3. Search & Replace** | Table inventory, transformer, job runner (§3.5), undo log with conflict detection (§3.6), UI with safety steps, offered from findings | All Section 15 tests plus the job, serialization and undo tests in §6a pass |
| **4. Reports & verification** | Repair Center, report builder, exports, history, rescan | Reports contain no secrets (automated check); retention works |
| **5. QA & release** | Multisite, HPOS, large-table and permission tests; readme, screenshots, ZIP | Pre-submission checklist (guide §21) fully true |

### 6a. Required test suites

Unit tests run on PHPUnit with Brain Monkey (no WordPress needed).
Integration tests run against a real WordPress 7.1 install, on SQLite in CI
and also on MySQL/MariaDB before a release.

| Suite | What it must prove |
|---|---|
| **Snapshots** | Range encoding round-trips any sorted ID list, including empty lists, a single ID, all gaps, and 1,000,000 IDs; capture is resumable mid-table; the comparison names exactly the IDs deleted after capture; IDs added after capture are reported separately as "new since snapshot"; exports contain only allowlisted keys (automated PII scan for emails, names and addresses); malformed, oversized, wrong-version and tampered (checksum mismatch) uploads are rejected |
| **WooCommerce: missing orders** | With orders deleted after the snapshot, each missing order ID is listed, under HPOS and under legacy storage; refunds are tracked separately from orders |
| **HPOS compatibility** | HPOS on, HPOS off, sync on and off, and a storage-mode change between snapshot and comparison; table-presence checks; nothing ever writes to order tables |
| **Interrupted jobs** | A batch killed mid-way (simulated fatal after N rows) resumes to the same final state as an uninterrupted run; an expired lease can be reclaimed, a live lease cannot; two concurrent resume requests process the batch once; cancel stops at the batch boundary |
| **Serialized data integrity** | Nested arrays, multibyte strings, escaped JSON inside serialized values, values that are already corrupt, objects, `C:` custom serialization and double-serialized strings: corrupt values and anything containing objects are skipped and reported; every written value round-trips; byte-level fixtures for Elementor-style JSON |
| **Undo conflicts** | Undo restores untouched cells exactly; a cell edited after the run is reported as a conflict and left unchanged; a deleted row is a conflict; overlapping jobs block out-of-order undo; repeating an undo is a no-op |
| **Dry runs** | The database checksum of every table is identical before and after any dry run or scan |
| **Permissions and input** | Subscriber, editor and subsite-admin requests are refused on every route; invalid input is rejected with a message, not cleaned silently |
| **Settings** | Saving each tab leaves the other tabs' values unchanged; unknown keys are ignored; an unchecked box on the saved tab turns off |
| **Uninstall** | With the removal option off (the default), every plugin table and option survives deletion; with it on, all are removed and nothing else is touched |

Each phase lands as a feature branch and a pull request. Nothing merges to
`main` while CI is failing.

---

## 7. Phase 1 changes, in order

1. Replace the template placeholders. Set the plugin header to
   WordPress 6.5 / PHP 7.4 / Tested up to 7.1.
2. Add the namespaced autoloader, the `Plugin` bootstrap, and
   `Activator`/`Deactivator`.
3. Create the schema with `dbDelta` and a stored database version. Include
   `umin_snapshots`. Handle network activation and new sites on multisite.
4. Add the `Capabilities` meta-cap map and the `UMIN_DISABLE_WRITES`
   constant.
5. Add the top-level admin menu with the gold label from an enqueued stylesheet.
   Add the Dashboard screen, which opens with the Scan Website action and the
   category grid, and the Settings screen. The other sections are added as
   their phase lands, so no placeholder screens ship. Until Phase 2 lands, the
   Scan Website button is shown but disabled, with the note "Scanner arrives
   in the next build".
6. Add `Settings` with scoped saves (declared fields only), validation and
   rejection.
7. Add the `REST_Controller` base and a `GET /status` route used by the
   dashboard.
8. Add the uninstall handler (keeps data unless the admin opted in, per
   site on multisite), `license.txt`, the full readme, and the External
   Services section.
9. Add tooling: `composer.json` (WPCS 3, PHPCompatibilityWP, PHPUnit),
   `phpcs.xml.dist`, and a CI workflow for PHPCS next to the existing
   Plugin Check workflow.
10. Validate: PHP lint, PHPCS, Plugin Check with all checks, and a live
    install of the built ZIP on WordPress 7.1.

## 8. Open items for the owner

- Move the repository from `paramedic192` to the Unstoppable-Solutions
  organization so the deploy workflow can use the organization's SVN secrets
  (brief §14).
- ~~Uninstall default~~: decided. Data is kept by default and removal is an
  explicit opt-in (§3.7).
- ~~Repository transfer~~: done by the owner on 16 Nov 2026. The repository
  is now `Unstoppable-Solutions/unstoppable-migration-inspector`, with its
  history intact.
