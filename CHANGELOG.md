# Changelog

All notable user-facing changes are documented here. For WordPress.org users,
the same changelog lives in `readme.txt`. This file follows the format
described at https://keepachangelog.com.

## [Unreleased]

## [0.3.0] - 2026-11-16

Phase 2b: read-only migration scanner. Development build.

### Added
- Scan Website on the dashboard and a Scan Results screen. Checks old,
  staging and temporary addresses (plain, JSON-escaped and URL-encoded, in
  posts, custom fields, settings, comments and term fields), HTTPS and mixed
  content, core tables, table prefix and user roles, character sets, the
  uploads folder, missing Media Library files and image sizes, broken links
  to uploads, permalinks and .htaccess, missing theme/plugin files, overdue
  cron and Action Scheduler tasks, cache/debug constants, search engine
  visibility, WooCommerce pages, HPOS storage and tables, lookup/analytics
  data and coming-soon mode. Every finding has a severity, explanation,
  next step and the affected items. Resumable, batch-idempotent, bounded
  memory.
- REST routes for scans; one job runner for snapshots and scans.
- Manual testing guide for staging copies made with WPvivid Staging Pro.

### Changed
- Schema version 4: findings gain `kind` and `batch_key` columns.
- The dashboard reads the latest completed scan.

## [0.2.0] - 2026-11-16

Phase 2a: migration snapshots. Development build.

### Added
- Migration snapshots: resumable capture of record IDs and allowlisted
  fields for posts of every type, users, comments, terms and WooCommerce
  orders/refunds (HPOS and legacy storage), plus table row counts and a
  configuration fingerprint. Comparison lists exact IDs as not found,
  changed, created after the snapshot or not in the snapshot. Streamed JSON
  export and strictly validated import. CSV of missing IDs.
- Export Without Content Fingerprints.
- Snapshot identity check: comparing a snapshot with a site that may be
  unrelated, or with a copy that has drifted, needs an explicit
  acknowledgment, which is recorded with the result.
- Salted 16-bit content digest per post, so same-length content changes are
  reported. The salt travels with exports; see the readme FAQ for what that
  means for exported files.
- "Not found" wording and a caution that a missing order ID is not proof the
  order was lost.
- Persistent jobs with leases: work resumes after a closed browser or a
  server timeout; no background execution.
- bin/bump-version.sh and a test that the version agrees everywhere.

### Changed
- Table prefix changes are a warning only when user roles were not moved to
  the new prefix; backup, migration and staging tool tables are explained
  as normally differing between copies; compared table names are shown.

### Performance
- Snapshot capture reads posts and orders by primary key. On MariaDB this
  cut capture of 200,000 posts from about 25 s to about 2.5 s.

## [0.1.0] - 2026-11-16

Phase 1: foundation. Development build.

### Added
- Plugin foundation: namespaced autoloaded classes, activation and schema
  for the plugin's own tables, multisite support.
- Migration Health dashboard leading with Scan Website, with Passed,
  Warning, Critical, Not Tested and Not Applicable statuses.
- Tabbed settings that save each tab independently and reject invalid input.
- Capability model with a super-admin requirement for database changes on
  multisite and a `UMIN_DISABLE_WRITES` kill switch.
- REST status route.
- Uninstall that keeps all data unless an administrator opted in to removal.
