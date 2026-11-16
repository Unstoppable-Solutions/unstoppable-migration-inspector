# Migration Snapshots: Design (Phase 2a)

**Status:** Implemented on `feature/phase-2a-snapshots`. The §6 tests pass on SQLite and MariaDB 10.11.
**Read-only guarantee:** Capturing and comparing never write to any table
except the plugin's own. Nothing here modifies site content.

A snapshot answers a question that inspecting the destination alone cannot:
*which records that existed before the move are not here now?*

---

## 1. What is captured

### 1.1 Tracked record types (exact IDs)

Each type is stored as the full sorted list of its IDs, plus a few small,
non-personal numeric fields per record. The comparison uses those fields to
tell a **changed** record from an untouched one.

| Type key | Source | Per-record fields (besides ID) | Excluded |
|---|---|---|---|
| `post:{post_type}` (one per type: `post`, `page`, `attachment`, `product`, `product_variation`, `nav_menu_item`, `wp_block`, custom types…) | `posts` | status code, modified time (GMT, unix), content length in bytes | `revision`, `auto-draft` status, `oembed_cache`, `customize_changeset`, all WooCommerce order types (tracked below) |
| `user` | `users` (single site); members of this site (`{prefix}capabilities`) on multisite | primary role code | — |
| `comment` | `comments` | approval code, comment type code | — |
| `term:{taxonomy}` | `term_taxonomy` | object count | — |
| `wc_order`, `wc_refund` (and other registered WooCommerce order types) | HPOS: `wc_orders`; legacy: `posts` | status code, modified time (GMT, unix) | — |

These are **counted only** and compared as counts:
- revisions
- every table that starts with this site's prefix (row counts, which also
  catch whole plugin tables that are missing or truncated)

"Code" fields (status, role, comment type) are stored as small integers. A
per-snapshot dictionary maps them back to their names (`publish`, `wc-processing`,
`editor`…). Those names are WordPress or plugin vocabulary, not personal data.

### 1.2 Configuration fingerprint

- `home`, `siteurl`, WordPress version, PHP version, database server version,
  table prefix, multisite flag and blog ID
- permalink structure, active theme (stylesheet and version), active plugins
  (file and version), upload base URL
- WooCommerce: version, HPOS enabled, compatibility sync enabled, and the page
  IDs for shop, cart, checkout and my account

### 1.3 Never captured (privacy allowlist)

Only the fields listed above are read into a snapshot. Specifically **never**:
- names, emails, logins, display names, addresses or phone numbers
- IP addresses, user agents, passwords or hashes, user meta values
- post titles, slugs, content, excerpts or meta values
- order totals, items, billing or shipping data, notes, payment details or
  customer IDs
- `admin_email`, security keys or anything from `wp-config.php` beyond the
  table prefix

An automated test scans every export for email addresses, known user logins,
display names, test customer names and post titles, and fails on any match.

---

## 2. Storage and resource limits

- Header row in `umin_snapshots`, holding status, label, origin
  (`local`/`imported`), configuration, the code dictionaries, per-type totals
  and the overall checksum.
- Records go in `umin_snapshot_chunks`: one row per up to *N* records of one
  type, in ascending ID order (*N* = the "Rows per batch" setting, default
  500, maximum 5,000). Columns:
  - `snapshot_id`, `record_type`, `seq`, `first_id`, `last_id`, `row_count`
  - `payload`: the encoded chunk
  - `sha256` of the decoded canonical chunk
- **Chunk encoding (`c1`):** columnar.
  - IDs: delta-encoded unsigned varints
  - each field: varints, zigzag for deltas of times
  - the whole chunk: gzip-deflated, then base64
  - Sequential IDs cost about one byte each. A chunk is decoded only when it
    is needed.
- **Capture** is a persistent job (see plan §3.5). Each step:
  - reads the next batch by keyset (`WHERE ID > :last ORDER BY ID LIMIT N`)
  - selects only the fields above (`LENGTH(post_content)`, never the content
    itself)
  - writes one chunk and saves the cursor

  Memory per step is bounded by *N* rows of a few integers each.
- **Table row counts:**
  - exact `COUNT(*)` for tables estimated under 1,000,000 rows
  - otherwise the `information_schema` estimate, marked "approximate"
  - one table per step at most
- Each request stops within the time budget (default 8 s). A closed browser or
  timeout leaves the job paused at its last committed chunk.
- A snapshot is only usable once its status is `complete`. Partial snapshots
  can be resumed or deleted, never compared.

### Consistency note shown in the UI

A snapshot is taken while the site is live. Records created or deleted
*during* capture may be counted inconsistently. For the cleanest result,
capture just before the move, ideally in maintenance mode or after placing
the shop in catalogue-only mode.

---

## 3. Comparison

Comparison is also a persistent job. It runs on the **destination** (or on the
same site, as a "what changed since" check).

For each tracked type, a streaming merge-join walks the snapshot's chunks and
the live table in ascending ID order, *N* records at a time. Each ID ends in
exactly one bucket:

| Bucket | Rule |
|---|---|
| **Missing** | In the snapshot, not on this site. This is the headline finding. |
| **Changed** | On both, with a different status, modified time, content length, role, approval or object count. Each changed field is listed with before and after values. |
| **Unchanged** | On both, with identical fields. Counted only. |
| **New since snapshot** | Not in the snapshot, with a creation time on this site after the snapshot was taken. |
| **Not in snapshot** | Not in the snapshot, but created *before* the snapshot (or with no creation time). Reported separately, because it usually means data was merged from another source, or the snapshot was taken on a different site. |

- **Creation time:** `post_date_gmt` for posts, `user_registered` for users,
  `comment_date_gmt` for comments, `date_created_gmt` for orders. Terms have
  no creation time, so they can never be "new since snapshot", only "not in
  snapshot".
- **Content length shrinking** gets its own hint ("content is smaller than
  before, which can mean it was cut off during import, or a character set
  problem").
- **WooCommerce storage:**
  - Each side reads from its own authoritative store: HPOS → `wc_orders`,
    legacy → `posts`.
  - Order IDs are preserved when WooCommerce moves orders between storage
    modes, so the comparison is valid when the mode changed. The result says
    which mode each side used.
  - If WooCommerce is inactive on the destination but orders were in the
    snapshot, the orders are still compared:
    - against `wc_orders` if that table exists
    - otherwise against `posts`
    - and the result is labelled accordingly.
- **Table row counts:** a table that is missing, or whose rows fell by more
  than 1% (and by at least one row), is flagged.
- **Configuration:** each differing value is listed (different theme,
  plugins missing or inactive, permalink change, HPOS mode change, WooCommerce
  page IDs pointing to missing pages).

Results are stored in `umin_comparisons` (header and per-type totals) and
`umin_comparison_items`, one row per non-unchanged ID with a small JSON
detail. They are paginated in the UI, and missing IDs can be downloaded as
CSV (type, ID).

---

## 4. Export and import

- **Export** streams a JSON document through `admin-post.php`. It requires the
  view capability and a nonce, and is sent with `Content-Disposition:
  attachment`. Chunks are written one at a time, so memory use stays flat.
  - Top-level keys: `format` (`umin-snapshot`), `format_version` (1),
    `exported_at`, `plugin_version`, `snapshot` (header fields from the
    allowlist), `chunks` (array of `{type, seq, first_id, last_id, count,
    sha256, payload}`), `checksum`.
- **Import** posts the file as multipart to `admin-post.php`, with the view
  capability and a nonce.
  1. Size limit: 32 MB by default (filterable), checked before reading.
  2. Read from PHP's temporary upload file only. **Never moved or written**
     to the uploads folder or anywhere else.
  3. Parsed with `json_decode` only. Nothing is ever unserialized.
  4. Strict schema validation:
     - only known keys; types and lengths checked
     - type keys must match `post:[a-z0-9_-]{1,20}`, `term:[a-z0-9_-]{1,32}`,
       `user`, `comment` or `wc_[a-z_]{1,30}`
     - ID ranges ascending and non-overlapping
  5. Every chunk is decoded with size limits (a decompression-bomb guard: each
     chunk's decoded size is capped by `count` × the maximum record width),
     then re-hashed. Its `sha256` and the overall checksum must match.
  6. Only after everything validates is the snapshot stored, with
     `origin = imported`.
- **The checksum detects corruption, not forgery.** Someone who can edit the
  file can recompute it. A forged file can only make a comparison report the
  wrong IDs: values are integers and allowlisted strings, escaped on output,
  and never executed or unserialized. The UI says imported snapshots should
  come from a source you trust.

---

## 5. Permissions

- Capture, compare, export and import need the view capability
  (`manage_options`). None of them modify site data.
- Deleting a snapshot (the plugin's own data) needs the same capability and
  asks for confirmation.
- On multisite, snapshots belong to the site they were taken on.

---

## 6. Tests (Phase 2a gate)

Unit:
- the codec round-trips: empty, single, sparse, dense, `PHP_INT_MAX`-adjacent
  and 1,000,000-ID lists
- the decoder rejects truncated, oversized and wrong-checksum payloads
- merge-join classification, including every bucket and each edge position
- import validator: bad keys, types, ordering, sizes and checksums
- the privacy allowlist

Integration: WordPress 7.1, WooCommerce 11.3, on SQLite and MariaDB.
- Seeded posts, pages, attachments, users, comments, terms and orders. After
  capture, some records are deleted, changed and added. The comparison must
  list **exactly** those IDs in the right buckets.
- The same test with HPOS on, with legacy storage, and with the mode switched
  between capture and comparison.
- Export from site A, import on a fresh site B, then compare. Tampered,
  oversized and malformed files are rejected.
- A capture interrupted mid-type (lease expired) resumes to a result
  byte-identical to an uninterrupted capture.
- Large data: at least 200,000 posts and 20,000 orders captured and compared
  with `memory_limit=128M`, peak memory recorded.
- The PII scan of exports passes.
- The checksum of every non-plugin table is identical before and after
  capture and comparison (proving read-only).
