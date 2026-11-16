# Unstoppable Migration Inspector

A free WordPress plugin that checks a site after it has been moved to a new host, server or domain, explains what did not transfer correctly, and offers safe, verifiable repairs only where they can be done safely.

> **Status:** Phase 1 (foundation) in development. Not ready for production. See [`docs/development-plan.md`](docs/development-plan.md) for the plan, scope and milestones.

## Principles

- **Inspection first.** The dashboard leads with *Scan Website*. Repair tools, including database search and replace, are offered from the findings that need them.
- **Useful without ever repairing.** The scanner, migration snapshots and reports stand on their own.
- **Extremely conservative writes.** Anything that cannot be transformed and verified safely is skipped and reported.
- **Standalone and free.** No account, no paid tier, no outbound requests, and no dependency on other Unstoppable Solutions plugins.

## Requirements

- WordPress 6.5 or later (tested up to 7.1)
- PHP 7.4 or later

## Development

```bash
composer install
composer lint      # php -l on every file
composer phpcs     # WordPress Coding Standards + PHP 7.4 compatibility
composer test      # PHPUnit unit tests (no WordPress needed)
```

Live tests against real WordPress installs (see each script's header for downloads and options):

```bash
DL_DIR=/path/to/downloads tests/smoke/run.sh                        # built ZIP, screens, settings, permissions, Plugin Check
DL_DIR=/path/to/downloads MULTISITE=1 DB=mysql tests/smoke/run.sh
DL_DIR=/path/to/downloads tests/integration/run.sh                  # snapshots: HPOS, legacy, storage switch, two-site migration
DL_DIR=/path/to/downloads DB=mysql tests/integration/run.sh large   # 200k posts + 20k orders at memory_limit=128M
```

### Layout

| Path | Purpose |
|---|---|
| `unstoppable-migration-inspector.php` | Bootstrap: constants, autoloader, hooks |
| `includes/core/` | Plugin, activation, schema, capabilities, settings, health summary |
| `includes/admin/` | Admin menu, screens, assets |
| `includes/rest/` | REST API controllers (`unstoppable-migration-inspector/v1`) |
| `includes/jobs/` | Persistent, lease-guarded jobs (state in the database, execution per request) |
| `includes/snapshots/` | Snapshot capture, comparison, chunk codec, export/import |
| `assets/` | Admin CSS (and JS as features land) |
| `tests/unit/` | PHPUnit + Brain Monkey |
| `tests/smoke/` | Live WordPress checks run with WP-CLI |
| `tests/integration/` | Snapshot scenarios with WooCommerce on real installs |
| `docs/` | Plan and design notes (not shipped) |

### Conventions

- Namespace `UnstoppableSolutions\MigrationInspector`; prefix `UMIN_` / `umin_`.
- Text domain `unstoppable-migration-inspector`.
- Feature branches and pull requests; CI must pass before merging.
- Dev files (`docs/`, `tests/`, tooling) are excluded from the release ZIP by `.distignore`.

## Licence

GPL-2.0-or-later. See [`license.txt`](license.txt).
