# Changelog

All notable user-facing changes are documented here. For WordPress.org users,
the same changelog lives in `readme.txt`. This file follows the format
described at https://keepachangelog.com.

## [Unreleased]

### Changed
- Schema version 4: findings gain `kind` and `batch_key` columns.
- The dashboard reads the latest completed scan.

### Performance
- Snapshot capture reads posts and orders by primary key. On MariaDB this
  cut capture of 200,000 posts from about 25 s to about 2.5 s.

### Added
- Plugin foundation: namespaced autoloaded classes, activation and schema
  for the plugin's own tables (snapshots, scans, findings), multisite support.
- Migration Health dashboard leading with Scan Website (scanner not yet
  included), with Passed, Warning, Critical, Not Tested and Not Applicable
  statuses.
- Tabbed settings that save each tab independently and reject invalid input.
- Capability model with a super-admin requirement for database changes on
  multisite and a `UMIN_DISABLE_WRITES` kill switch.
- REST status route.
- Uninstall that keeps all data unless an administrator opted in to removal.
- Migration snapshots (Phase 2a): resumable capture of record IDs and
  allowlisted fields for posts of every type, users, comments, terms and
  WooCommerce orders/refunds (HPOS and legacy storage), plus table row
  counts and a configuration fingerprint. Comparison lists exact IDs as
  missing, changed, created after the snapshot or not in the snapshot.
  Streamed JSON export and strictly validated import. CSV of missing IDs.
- Snapshot identity check: comparing a snapshot with a site that may be
  unrelated needs an explicit acknowledgment, which is recorded with the
  result.
- Salted 16-bit content digest per post, so same-length content changes are
  reported. Nothing readable is derived from content.
- "Not found" wording and a caution that a missing order ID is not proof the
  order was lost.
- Read-only migration scanner (Phase 2b): Scan Website on the dashboard and
  a Scan Results screen. Checks old/staging/temporary addresses (plain,
  JSON-escaped and URL-encoded, in posts, custom fields, settings, comments
  and term fields), HTTPS and mixed content, core tables, table prefix and
  user roles, character sets, uploads folder, missing Media Library files
  and image sizes, broken links to uploads, permalinks and .htaccess,
  missing theme/plugin files, overdue cron and Action Scheduler tasks,
  cache/debug constants, search engine visibility, WooCommerce pages, HPOS
  storage and tables, lookup/analytics data and coming-soon mode. Every
  finding has a severity, explanation, next step and the affected items.
  Resumable, batch-idempotent, bounded memory.
- Manual testing guide for staging copies made with WPvivid Staging Pro.
- Persistent jobs with leases: work resumes after a closed browser or a
  server timeout; no background execution.
