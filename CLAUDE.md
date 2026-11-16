# unstoppable-migration-inspector

> This file is for Claude Code sessions working on this repo. For a human-oriented overview see `README.md`.

## What this plugin does

Checks a WordPress site after it has been moved and explains what did not transfer correctly. Read-only by default; repairs are offered only where they can be done safely and verified. Plan: `docs/development-plan.md`.

- **Main PHP file:** `unstoppable-migration-inspector.php`
- **Code prefix:** `UMIN_` (constants); `umin_` (options, tables, hooks, handles). Classes are namespaced under `UnstoppableSolutions\MigrationInspector` instead of prefixed.
- **Package:** `UnstoppableSolutions\MigrationInspector`
- **WordPress.org slug:** `unstoppable-migration-inspector`
- **WP.org URL:** https://wordpress.org/plugins/unstoppable-migration-inspector/

## Base44 Pro mapping

- **Base44 app:** _(none yet — fill in when a Pro version is scaffolded there)_
- When updating code that affects both the free (this repo) and the Base44 Pro app, apply the matching change in both places in the same session.

## Versioning (owner rule, 16 Nov 2026)

Change the version with every update that is handed over or merged:
- each development phase raises the minor version (0.2.0 = snapshots, 0.3.0 = scanner);
- every later build with changes raises the patch version (0.3.1, 0.3.2…).

Add the changelog entry to `readme.txt` (`= X.Y.Z =`) and `CHANGELOG.md` (`## [X.Y.Z]`) first, then run `bin/bump-version.sh X.Y.Z`, which updates the `Version:` header, `UMIN_VERSION` (also the CSS/JS cache-busting version) and `Stable tag:`. `tests/unit/VersionTest.php` fails if they disagree. Name delivered ZIPs `unstoppable-migration-inspector-X.Y.Z.zip`.

## Release workflow

Releases are deployed to WordPress.org SVN automatically by GitHub Actions. The workflow is identical across every `unstoppable-solutions/*` plugin:

1. Finish feature work on `main`.
2. Bump the version in **all** of these:
   - `unstoppable-migration-inspector.php` — `Version:` header
   - `unstoppable-migration-inspector.php` — `UMIN_VERSION` constant (if present)
   - `readme.txt` — `Stable tag:` (and `Tested up to:` if WordPress has a new .x)
   - `readme.txt` — add a `== Changelog ==` entry
   - `CHANGELOG.md`
   Then run `composer phpcs`, `composer test` and `tests/smoke/run.sh` on the built ZIP.
3. Commit and push `main`.
4. On GitHub, create a new **Release** tagged `vX.Y.Z` (semver) with the changelog in the body.
5. The `deploy-to-wporg.yml` workflow fires and pushes the release to WP.org SVN.

## CI

Every push and PR runs the official WordPress Plugin Check against the codebase (`.github/workflows/plugin-check.yml`). CI must pass before merging. The deploy workflow (`.github/workflows/deploy-to-wporg.yml`) only fires on published Releases.

**Secrets used by deploy:** `SVN_USERNAME`, `SVN_PASSWORD` — org-level secrets on `unstoppable-solutions`. Nothing per-repo.

`coding-standards.yml` also runs PHP lint, PHPCS (WordPress + PHPCompatibilityWP 7.4+) and the PHPUnit unit tests on every push and PR. Locally: `composer lint`, `composer phpcs`, `composer test`, `tests/smoke/run.sh` for the live WordPress check and `tests/integration/run.sh` for the snapshot and scanner scenarios (WooCommerce, HPOS/legacy, migration, scan, `large` on MySQL). Manual testing with staging copies: `docs/manual-testing-wpvivid.md`. Do not start Phase 3 (database modifications) until the scanner and snapshots are thoroughly tested.

## Known phpcs:ignores specific to this plugin

- `includes/core/class-schema.php` `drop_tables()` and `uninstall.php`: `DROP TABLE IF EXISTS %i` on the plugin's own tables (fixed names, not input). DirectQuery/NoCaching/SchemaChange are expected for uninstall.
- `includes/admin/class-settings-page.php` `current_tab()`: `NonceVerification.Recommended` on a read-only `?tab=` value that is validated against an allowlist.
- `includes/admin/class-scan-page.php` `render()`: `NonceVerification.Recommended` on read-only `?scan=`, `?group=`, `?paged=` view parameters (absint / sanitized; only used to choose what to display).
- `includes/scanner/class-row-reader.php` `prefilter()` and `read_group()`: `PreparedSQL.NotPrepared` and `PluginCheck.Security.DirectDB.UnescapedDBParameter`. The SQL string is assembled only from fixed `%i`/`%s`/`%d` placeholders (one per column, pattern or ID) and every value goes through `$wpdb->prepare()`; the column count varies per table, so a literal string is not possible.
- `includes/scanner/checks/class-attachment-files-check.php` `missing_sizes()`: `unserialize()` of core attachment metadata with `allowed_classes => false` (read only, objects refused).

## Project rules (owner decisions, 16 Nov 2026)

- Inspection first: dashboard leads with Scan Website; Search & Replace is a tool offered from findings, never the headline.
- Read-only scanner must be fully useful on its own.
- Serialized data: skip and report anything that cannot be transformed and verified safely; serialized objects are always skipped in v1.0.
- Undo must detect conflicts (compare-and-swap on the value we wrote) and never overwrite later edits. The undo log is not a backup; say so in the UI.
- Job state is persistent (DB); execution is one bounded batch per authenticated request. No background execution in v1.0.
- Uninstall keeps all data unless an admin opted in to removal.
- Standalone: no dependency on Activity Tracker, Plugin Guardian, Site Manager or other Unstoppable plugins.
- Never claim a test passed unless it actually ran.

## Shared conventions across the Unstoppable Solutions org

- **GPL-2.0-or-later** on everything.
- **One author** for Contributors: in `readme.txt`: `paramedic192` (Dave Stevens).
- **Tested up to** is bumped as WordPress ships each new .x. It lives in `readme.txt` **only**: Plugin Check (Nov 2026) reports `plugin_header_tested_up_to_not_allowed` as an error if the main PHP header also declares it.
- **Plugin Check CI config** (`.github/workflows/plugin-check.yml`) is standard across the org: `exclude-checks: late_escaping` + `exclude-files` lists the GitHub-only files (`.github`, `.distignore`, `.gitignore`, `.gitattributes`, `README.md`, `CLAUDE.md`, `SECURITY.md`, `CONTRIBUTING.md`, `CHANGELOG.md`).
- **`.distignore`** keeps GitHub-only files out of the WP.org zip (CI, docs, this file).
- **Prefer `phpcs:ignore` + inline rationale** over removing the ignored check from CI entirely — a specific ignore documents the decision at the call site; a check removal silently covers future code too.
- **Don't `-c user.email=` with a random identity** when committing — use `fieldmedics4@gmail.com` / `Dave Stevens` for authorship, with Claude as Co-Authored-By.

## Working with the plugin from Claude Code

- When Claude attaches this repo in a future session, this file loads automatically.
- Workflow for a bugfix / feature:
  1. Make the change on `main` (or a branch).
  2. Watch Plugin Check CI — do not merge red.
  3. For a release: follow the Release workflow section above.
- The free plugin and its Pro add-on live in **separate repos**. This repo is the free side; Pro has its own repo (private) when it exists.

## Repository layout

Standard WordPress plugin layout — `unstoppable-migration-inspector.php` is the bootstrap, `includes/` holds the classes, `assets/` holds CSS/JS, `languages/` holds the `.pot`, `readme.txt` is the WP.org directory listing, `.github/` holds the CI and deploy workflows.
