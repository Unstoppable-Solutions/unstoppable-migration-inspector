# Changelog

All notable user-facing changes are documented here. For WordPress.org users,
the same changelog lives in `readme.txt`. This file follows the format
described at https://keepachangelog.com.

## [Unreleased]

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
