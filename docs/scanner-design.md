# Read-Only Migration Scanner: Design (Phase 2b)

**Status:** implemented on `feature/phase-2b-scanner`. Test results: §7.

**Read-only guarantee:** checks only read site tables, files and settings. The only writes go to the plugin's own tables: `umin_scans`, `umin_findings` and `umin_jobs`. An integration test fingerprints every non-plugin table before and after each scan.

---

## 1. Principles

- **Inspection first.** The dashboard's primary action is **Scan Website**. Results open on the **Scan Results** screen.
- **Every finding is actionable.** Each kind of finding declares:
  - a severity
  - a title
  - an explanation of why it matters
  - a recommended next step

  These are defined in code (`Check::kinds()`) and translated when shown. A stored finding holds only what is specific to the affected object: the object, the location, a short message and details.
- **No false confidence.** A category is "Passed" only when every applicable check in it ran and found no critical or warning items. A check that fails with an error makes its category "Not tested". Notices never make a site look unhealthy.
- **Conservative matching.**
  - Old-address findings need a boundary match: `old.com` does not match `old.com.au`.
  - On a subdirectory copy, links to the current, longer address (`example.com/staging01`) are subtracted from matches of the shorter one (`example.com`).
  - Detected addresses are labelled with their source, and the screen says detected addresses can belong to other sites.

## 2. Severities

| Severity | Meaning | Examples |
|---|---|---|
| Critical | Something is broken or will break | Core tables missing; users' roles under an old table prefix; checkout page missing; HPOS on with its tables missing; orders only in the store WooCommerce does not read |
| Warning | A visible problem | Links to the old domain; missing media files; insecure resources on HTTPS; search engines discouraged on a live address; overdue cron |
| Notice | Worth knowing, often expected | Plain permalinks; site not on HTTPS; object-cache drop-in present; lookup tables stale |

## 3. Architecture

```text
includes/scanner/
  class-check.php            base: key, category, label, kinds(), applicable(), run()
  class-batched-check.php    start() → cursor; batch(cursor) → findings + next cursor
  class-check-registry.php   all checks, in run order (quick checks first)
  class-scan-context.php     facts fixed at scan start + old-address detection
  class-location.php         host/path "locations", encodings, boundary counting
  class-row-reader.php       prefilter (IDs and sizes only) + size-limited fetch
  class-db-info.php          table list, raw options, SQLite detection
  class-finding.php          value object
  class-severity.php
  class-scan-handler.php     the job: discover → checks → finalize
  class-scan-repository.php  umin_scans
  class-finding-repository.php umin_findings (batch-idempotent, capped)
  class-scan-service.php     start/resume/delete
  checks/                    one class per check
includes/rest/class-scans-controller.php   POST /scans, GET|DELETE /scans/{id}
includes/jobs/class-job-registry.php       one runner for capture, compare, scan
includes/admin/class-scan-page.php         Scan Results screen
assets/js/scan.js                          drives the job from the dashboard and results screen
```

### Job flow

The scan reuses the Phase 2a persistent job engine: a lease and one bounded batch per authenticated request, with no background execution.

1. **discover:** build the `Scan_Context`:
   - addresses, HTTPS, uploads folder, table prefix
   - old locations
   - the request host and scheme

   Then decide which checks apply. Checks that do not apply are recorded with their reason.
2. **checks:** each step runs one quick check, or one batch of a batched check.
   - A check that throws is recorded as "could not complete", and the scan moves on.
3. **finalize:** count findings per check, kind and severity, and store a summary per category in the scan record. The dashboard reads the latest completed scan.

### Idempotent batches

- Findings are stored with `batch_key` = the cursor the batch started from.
- Before a batch stores its findings, it deletes any stored with the same scan, check and batch key.
- A request that timed out after storing findings but before saving the cursor therefore repeats cleanly, without duplicates. Tested.

### Bounded work and memory

- Batched table checks never read whole tables. `Row_Reader::prefilter()` asks the database for rows inside an ID window (batch size × 10 IDs) whose text matches a LIKE pattern, returning IDs and lengths only.
- `fetch()` then reads matching rows in groups of at most 2 MB. Rows over 8 MB are reported as "too large to check" instead of being read.
- At most 500 findings are stored per check and scan. The rest are counted, and the screen says "Only the first 500 are listed; N more were found".

### Schema v4

`umin_findings` gains `kind` and `batch_key`, plus the index `(scan_id, check_key, batch_key)`. The upgrade runs through dbDelta on the next admin load. No data is lost.

## 4. Old-address detection

Sources, strongest first. At most 12 addresses are searched.

| Source | Confidence | How |
|---|---|---|
| Settings → Previous domains | confirmed | Entered by the administrator |
| Migration snapshot | likely | `source_home` of the snapshots stored on this site |
| Post GUIDs | likely | WordPress never rewrites GUIDs, so the base address in `http://old/?p=1` or `…/wp-content/uploads/…` reveals earlier addresses |
| Temporary patterns | possible | Hosts linked from the 200 most recent posts that look like staging, local or host-temporary addresses (`localhost`, IPs, `*.local`, `*.test`, `staging.*`, `dev.*`, `*.wpengine.com`, `*.kinsta.cloud`, `*.instawp.xyz`, `*.sg-host.com`…) |

Each address is matched in three encodings, all case-insensitively:

- plain: `//old.com/path`
- JSON-escaped: `\/\/old.com\/path`
- URL-encoded: `%2f%2fold.com%2fpath`

That covers block markup, page-builder JSON and serialized PHP **without unserializing anything**.

## 5. Checks

| Category | Check | Kinds (severity) | Notes |
|---|---|---|---|
| Domains & URLs | `site_urls` | host_mismatch (W), scheme_mismatch (W), constant_override (N), request_mismatch (N) | Raw stored values compared with WP_HOME/WP_SITEURL |
| Domains & URLs | `old_urls_{posts,postmeta,options,comments,termmeta}` | old_reference (W) | Skips revisions, auto-drafts, oEmbed caches, transients, `home`/`siteurl`, the plugin's own options, `_wp_attached_file` and `_wp_attachment_metadata` |
| HTTPS | `https_setting` | https_not_used (W), no_https (N), insecure_visit (N) | No outbound requests |
| HTTPS | `insecure_urls_{…}` | insecure_reference (W) | `http://` + this site's address, on HTTPS sites only |
| HTTPS | `mixed_content` | insecure_resource (W) | `src`, `srcset`, `data-src`, `poster`, CSS `url()` and stylesheet `<link>` over http:// to other hosts. Plain `<a href>` is not mixed content |
| Database | `core_tables` | missing_table (C) | |
| Database | `table_prefix` | roles_option_missing (C), capabilities_prefix (C), other_installs (N) | Single site only for capabilities |
| Database | `charset_collation` | legacy_charset (W), mixed_collations (N) | MySQL and MariaDB; not applicable on SQLite |
| Media | `uploads_folder` | uploads_error (C), not_writable (W), custom_path_stale (W), offloaded (N) | |
| Media | `attachment_files` | missing_file (W), case_mismatch (W), absolute_path (W), missing_sizes (N), no_file_meta (N) | `file_exists()` only; metadata read with `allowed_classes => false`. With offloaded media, severities drop to Notice |
| Media | `content_media` | missing_linked_file (W) | Uploads links by full URL (uploads URL and Site Address) or root-relative path; `..` refused |
| Configuration | `permalinks` | rules_missing (W), htaccess_missing (W), htaccess_base (W), plain_permalinks (N) | `.htaccess` read only, 256 KB limit, Apache and LiteSpeed only |
| Configuration | `theme_plugins` | theme_missing (C), plugin_missing (W) | Includes network-active plugins |
| Configuration | `scheduled_tasks` | cron_overdue (W), actions_overdue (W), wp_cron_disabled (N) | Overdue means more than 1 hour late; Action Scheduler needs ≥ 10 overdue |
| Configuration | `config_constants` | content_url_host (W), content_dir_stale (C), cache_dropin (W), object_cache (N), debug_display (W) | Values never stored; WP_CONTENT_URL reduced to its host |
| Configuration | `search_visibility` | discouraged (W), staging_visible (N) | Staging recognised by temporary host patterns or `wp_get_environment_type()` |
| WooCommerce | `woo_pages` | checkout_page (C), store_page (W) | Cart and checkout must contain the block or shortcode |
| WooCommerce | `woo_order_storage` | hpos_tables_missing (C), orders_elsewhere (C), sync_mismatch (W) | Counts only; never touches order tables |
| WooCommerce | `woo_tables` | table_missing (C), lookup_stale (N), analytics_stale (N), coming_soon (W) | Coming soon not flagged on staging-looking hosts |

## 6. Known limits

- Old addresses are searched in the core tables listed above, not in other plugins' custom tables. Search & Replace (Phase 3) will offer a table picker.
- Case: matching is case-insensitive, but "boundary" means the next character is not a letter, digit, dot or hyphen. `old.com_backup` therefore matches.
- `.htaccess` checks only run when the server reports Apache or LiteSpeed. Nginx rules cannot be read from PHP.
- HTTPS checks make no outbound requests (SSRF policy). They compare the configured addresses with the connection the scan was started from.
- "Temporary" host detection can flag a real site with a matching name (for example a production site at `dev.something.com`). Such findings say "possible", and the admin can ignore them.

## 7. Tests

See the Phase 2b PR for the results that were run.

- **Unit (`tests/unit/ScannerTest.php`):**
  - location parsing, encodings and boundary counting, and subtraction of the shadowing current address
  - GUID bases and temporary host patterns
  - mixed-content resource parsing
  - missing linked files, attachment path resolution, missing sizes (serialized objects refused)
  - RewriteBase and category status rules
- **Integration (`tests/integration/scenario-scan.php`):**
  - **clean store:** no critical or warning findings; the scan changes no site table; the dashboard reads the scan
  - **damaged migration (http://old-site.test → https://new-shop.example.com) with planted problems:**
    - each problem reported on exactly the planted objects; decoys (`old-site.test.au`, `bold-site.com`, plain http links) not reported
    - JSON-escaped and serialized old URLs found
    - every finding kind has an explanation, severity and next step
    - roles under an old prefix are critical
    - an interrupted scan (lease held, then expired) resumes to exactly the same findings
    - repeating a step does not duplicate findings
    - starting while a scan is unfinished resumes it
- **Large (`scenario-large.php`):** 200,000 posts and 20,000 orders at `memory_limit=128M`. Every planted old-domain post is found, and no scan step may need more than 16 MB extra.
- **Smoke (`tests/smoke/run.sh`):**
  - REST permissions: anonymous 401, subscriber 403, admin without nonce 401
  - a full scan driven over REST
  - the results screen shows explanations and next steps, with no inline styles
  - a subscriber cannot open the results
  - Plugin Check clean
- **Manual:** `docs/manual-testing-wpvivid.md`.
