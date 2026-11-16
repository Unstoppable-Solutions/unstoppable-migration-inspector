# Migration Snapshots: Design (Phase 2a)

**Status:** Implemented on `feature/phase-2a-snapshots`. The §6 tests pass on SQLite, MariaDB 10.11 and MySQL 8.4, with PHP 8.3 and 7.4 (§6.1).
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
| `post:{post_type}` (one per type: `post`, `page`, `attachment`, `product`, `product_variation`, `nav_menu_item`, `wp_block`, custom types…) | `posts` | status code, modified time (GMT, unix), content length in bytes, 16-bit salted content digest (§1.4) | `revision`, `auto-draft` status, `oembed_cache`, `customize_changeset`, all WooCommerce order types (tracked below) |
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

### 1.4 Content digest (evaluated and adopted)

**Problem.** Status, modified time and content length miss an edit that keeps
the same length (a changed digit in a price table, a URL swapped for one of
equal length), and also an import that rewrites content without touching
`post_modified_gmt`.

**Options considered.**

| Option | Detects same-length change | Privacy | Cost |
|---|---|---|---|
| No hash (lengths only) | No | Nothing derived from content | — |
| Full hash (MD5/SHA-256 of content) | Yes | A full unsalted hash of short content (a one-word page, a coupon code) can be confirmed by guessing. Not acceptable in an export. | 16–32 bytes per post |
| **Salted 16-bit digest (adopted)** | Yes, missing about 1 in 65,536 changes | Leaks 16 bits per post to anyone holding the file (see "Privacy limits" below). The salt only stops matching across snapshots and precomputed tables. | 2 bytes per post |

**What is stored.** `LEFT(MD5(CONCAT(salt, post_content)), 4)`, computed in
SQL so the content never reaches PHP. The 8-byte random salt
(`content_salt`, hex) is kept in the snapshot header and exported with it, so
an imported snapshot can be compared on the destination.

**Privacy limits (stated plainly, not hidden).** The salt is in the export
because the destination needs it to compute matching digests. So the salt
does **not** protect against someone who holds the file:
- They can test any guess at a post's content: a wrong guess matches by
  chance 1 time in 65,536, so a match is strong evidence.
- Combined with the stored content length, short or predictable content can
  be recovered by trying every candidate. A 4-digit PIN as the only content
  of a private post has 10,000 candidates; on average about 0.15 wrong ones
  would also match, so the real one is usually identified.
- Long, unpredictable content cannot be recovered this way: one candidate
  in 65,536 matches, so among the astronomically many possible texts of that
  length a match does not single out the real one.

What the salt does do: digests from two snapshots cannot be compared with
each other, and no precomputed table works across files.

Mitigations in the product:
- The snapshots page and readme say exported files should be treated as
  confidential, and explain why.
- **Export Without Content Fingerprints** writes the same snapshot with
  every digest set to 0 and an empty salt (chunk and overall checksums are
  recomputed). Comparison then still finds not-found and changed records
  (status, dates, length), but not same-length edits.
- A snapshot that stays on the site where it was taken is in the site's own
  database, which already holds the content itself.

**Limits stated in the UI.** A digest mismatch with equal length is reported
as "content changed, although its size is the same". A matching digest is not
proof that content is identical. The digest is a change signal, not an
integrity check or a backup, and is never used to repair anything.

**Measured cost.** Stored record data for 200,000 posts and 20,000 orders grew
from 0.24 MB to 0.63 MB (random digests do not compress).

**Older snapshots.** A snapshot without a salt (empty `content_salt`) stores
a digest of 0, and the live side is read with digest 0 too, so the digest
never reports a change for it.

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
- Keyset scans on `posts` and `wc_orders` use `USE INDEX (PRIMARY)`. Without
  it MariaDB chose the `type_status_author` index and sorted most of the post
  type on every batch (one batch: 79 ms without, 8 ms with, 200,000 posts).
  MySQL 8.4 already chose the primary key. The SQLite translator accepts the
  hint.
- Each request stops within the time budget (default 8 s). A closed browser or
  timeout leaves the job paused at its last committed chunk.
- A snapshot is only usable once its status is `complete`. Partial snapshots
  can be resumed or deleted, never compared.

### Measured resource use (16 Nov 2026)

Large-site test: 200,000 posts and 20,000 WooCommerce orders (HPOS), batch
size 500, `memory_limit=128M`, time budget 8 s. "Extra memory per step" is
the most any single job step allocated beyond what was in use before it.

| Environment | Capture | Comparison | Extra memory per step (capture / comparison) | Result |
|---|---|---|---|---|
| PHP 8.3, MariaDB 10.11, before the index hint (3 runs) | 24.5–25.8 s | 2.2–2.5 s | 1.45 / 1.14 MB | 10/10 each |
| PHP 8.3, MariaDB 10.11, with the index hint (2 runs) | 2.5–2.6 s | 2.3–2.4 s | 1.48 / 1.14 MB | 10/10 each |
| PHP 8.3, MySQL 8.4.13 (2 runs) | 3.9–4.2 s | 3.7–4.1 s | 1.48 / 1.14 MB | 10/10 each |
| PHP 7.4.33, MySQL 8.4.13 | 4.3 s | 4.2 s | 10.87 / 10.59 MB | 10/10 |

- Stored snapshot record data: 0.63 MB. The 37 deleted posts and 11 deleted
  orders were listed by exact ID in every run.
- Per request, measured in a fresh PHP process on the large site:
  WordPress + WooCommerce + WP-CLI with no plugin work used 112.2 MB; a full
  8-second capture or comparison request peaked at 112.6–113.9 MB.
- PHP 7.4 uses about seven times more memory per step than PHP 8.3 (its
  arrays are less compact), still under the test's 16 MB-per-step ceiling.
  Lowering "Rows per batch" reduces it proportionally.

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
| **Not found** (internally `missing`) | In the snapshot, not on this site. This is the headline finding; see "Not found is not the same as lost" below. |
| **Changed** | On both, with a different status, modified time, content length, content digest, role, approval or object count. Each changed field is listed with before and after values. |
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

### Not found is not the same as lost

The UI and readme call the bucket **Not found** and say so explicitly for
WooCommerce orders: an order ID that is not found on this site means the
record is not in this database. It does not by itself prove the order was
lost. It may have been deliberately deleted, moved to another store, or the
snapshot may come from a different site. Each orders section with not-found
IDs shows that caution and suggests checking the source site or a backup
before treating the order as lost.

### Source/destination identity check

Comparing a snapshot with an unrelated site would report thousands of
"not found" IDs that mean nothing. Before a comparison starts, the snapshot
is checked against this site:

| Signal | How |
|---|---|
| Site key | A random key (`umin_site_key` option) created on activation and stored in every snapshot. It travels with a database copy, so a migrated site keeps it. |
| Site name | Salted hash of `blogname` (no name stored in clear) |
| Domain, table prefix | From the configuration fingerprint |
| ID overlap | Up to 50 sampled IDs per main type checked on this site |

Verdicts:

| Site key | ID overlap | Verdict | Needs acknowledgment |
|---|---|---|---|
| matches | ≥ 50% or nothing to sample | **same site** (below 90% the overlap is listed under "Differences noted") | no |
| matches | < 50% | **unverified** | yes |
| differs/absent | ≥ 90% plus name, domain or prefix match | **likely same** | no |
| differs/absent | ≥ 50%, or nothing to sample plus another signal | **unverified** | yes |
| differs/absent | anything else | **likely unrelated** | yes |

A matching key proves only that the database was copied from the
snapshot's site at some point. It does not prove the two still hold the same
records: an old staging copy, or a site built from a cloned template, keeps
the key but diverges. So the ID sample can override a matching key, and
address, prefix and name differences are always listed under "Differences
noted", even for a same-site verdict. An integration test empties a copied
site that kept the key and checks the comparison is refused without
acknowledgment. The last two need an explicit
acknowledgment: the REST call returns 409 `umin_identity_unconfirmed` with
the reasons, the UI asks the administrator to confirm, and the acknowledgment
and verdict are stored in the comparison result and shown above it.

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

Integration: WordPress 7.1, WooCommerce 11.3, on SQLite, MariaDB 10.11 and
MySQL 8.4, with PHP 8.3 and PHP 7.4 (see §6.1).
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

### 6.1 Test matrix actually run (16 Nov 2026)

| PHP | Database | Lint | Unit (111) | Smoke (103, incl. multisite and Plugin Check) | Integration (352) | Large (10) |
|---|---|---|---|---|---|---|
| 8.3 | SQLite | pass | pass | pass | pass | n/a (needs MySQL) |
| 8.3 | MariaDB 10.11.14 | — | — | pass | pass | pass ×2 (×3 more before the index hint) |
| 8.3 | MySQL 8.4.13 | — | — | pass | pass | pass (×1 more before the index hint) |
| 7.4.33 | MySQL 8.4.13 | pass | pass | pass | pass | pass |
| 7.4.33 | SQLite | — | — | not possible | not possible | — |

PHP 7.4 on SQLite cannot run: the SQLite Database Integration plugin (3.1.3)
requires SQLite 3.37, and the official PHP 7.4 image ships SQLite 3.34.1. This
is a limit of the test environment, not of this plugin.

Lint, unit, smoke and integration were run on the final Phase 2a code
(commit `95c8323`). The large-site runs were made on `27eb2ea`; later commits
changed only export options, the identity verdict, wording and tests, not
capture or comparison. GitHub Actions is configured to run lint (7.4, 8.1,
8.3, 8.4), PHPCS, PHPUnit (7.4, 8.3) and Plugin Check; its results are not
listed here because they could not be read from the development session.
