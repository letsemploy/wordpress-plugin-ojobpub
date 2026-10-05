# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

WordPress plugin that serves a site's job openings as an oJobPub 1.0 feed at `/.well-known/ojobpub.json`. The format is defined by `../schema/v1/ojobpub.json` (sibling repo, JSON Schema 2020-12) and the spec in `../docs/docs/ojobpub/specification.md`. `readme.txt` is the wordpress.org listing; `README.md` covers development.

## Commands

Everything runs in containers (podman by default; there is no local PHP). Override with `COMPOSE="docker compose" CONTAINER=docker`.

```sh
make up && make setup   # WordPress on http://localhost:8080 (admin/admin) + WP Job Manager, Job Postings, Plugin Check
make seed               # sample jobs in all three sources, incl. edge cases (bin/seed.php)
make feed               # print the served feed
make wp ARGS='option get ojobpub_settings'   # any WP-CLI command

make test               # PHPUnit (also validates generated documents against ../schema/v1/ojobpub.json)
make test ARGS='--filter test_amount'        # single test / test method
make phpcs              # WordPress Coding Standards + PHPCompatibility (PHP 7.4+)
make plugin-check       # wordpress.org Plugin Check against the running instance
make validate-all       # switch through every source, validate the served feed with check-jsonschema
make pot                # regenerate languages/ojobpub.pot
make zip                # dist/ojobpub-<version>.zip, honouring .distignore
```

The plugin dir is bind-mounted read-only into the containers, so PHP edits are live, but nothing in the container can write into the repo (that's why `make pot` pipes through stdout).

## Architecture

**Data flow:** `Source::jobs()` → raw arrays keyed by post ID using oJobPub field names → `Feed\Normalizer` (enforces every schema rule) → `Feed\Builder` (assembles, sorts, hashes) → `Feed_Service` (caches, tracks `lastUpdated`) → `Endpoint` (HTTP) and optionally `Static_File` (copy on disk).

- **Sources** (`includes/Sources/`): `Native_Source` (own CPT `ojobpub_job`, meta prefixed `_ojobpub_`), `WP_Job_Manager_Source`, `Job_Postings_Source`. `Plugin::sources()` collects them through the `ojobpub_sources` filter, keyed by `id()`; for the same id, the source registered last wins. This is documented public API, because job-plugin authors are meant to ship their own adapters. `Plugin::source()` falls back to native if the configured source is unavailable.
- **Adapters stay sloppy and the Normalizer stays strict.** Adapters map raw plugin data without validating it. The Normalizer strips HTML, truncates `description` to 1000 characters, parses amounts like `90'000`/`85k`, checks enums and ISO codes, and drops jobs whose `applyBefore` has passed. A job that is still invalid is skipped and recorded in `issues()`, which the settings screen shows; it never invalidates the whole feed. Put schema rules in the Normalizer, not in adapters.
- **`includes/Feed/` has no WordPress dependency.** Those files guard with `defined( 'OJOBPUB_TESTING' ) || defined( 'ABSPATH' )` and must avoid WP functions so they stay unit-testable (`tests/bootstrap.php` loads only them). Tests cover only this layer; adapters are verified via `make seed` + `make validate-all`.
- **`lastUpdated` changes only when the content changes:** `Feed_Service::build()` hashes the normalized content and only bumps the stored timestamp (option `ojobpub_state`) when the hash differs. The ETag derives from the same hash. Cache: transient `ojobpub_feed`. It is invalidated by post/meta/term/settings hooks of the active source's post types (deferred to `shutdown`), plus an hourly cron `ojobpub_refresh` so expired jobs drop out.
- **The document must stay schema-valid.** The schema has no `additionalProperties` at top level, on the employer or on jobs. Empty `employer.location` must encode as `{}` (stdClass), `jobs: []` as a list, and `version` as the string `"1.0"`.
- **Job plugin quirks** (verified against WP Job Manager 2.4.7 and Job Postings 2.8.2; see adapter docblocks):
  - Job Postings stores countries as names, which `Feed\Country` resolves to ISO codes.
  - Job Postings writes `1970-01-01` to `position_valid_through_date` when the deadline is empty.
  - WP Job Manager honours a per-job salary currency/unit only if its `job_manager_enable_salary_*` options are on.

  When changing an adapter, check the real plugin source in the container: `podman exec wordpress-plugin-ojobpub_wordpress_1 grep -rn ... /var/www/html/wp-content/plugins/<plugin>`.

## Conventions

- PHP 7.4 compatible, WordPress 6.2+. Class files are PSR-4 style under `includes/` (`OJobPub\Feed\Builder` → `includes/Feed/Builder.php`), loaded by the autoloader in `ojobpub.php`. The `WordPress.Files.FileName` sniff is disabled for that reason.
- Text domain `ojobpub`. Do not add `load_plugin_textdomain()`; Plugin Check flags it.
- Hooks, options and globals are prefixed `ojobpub_`. Public filters are listed in `readme.txt` under "For developers" and in `../docs/docs/publishing/wordpress.md`; keep both in sync when adding one.
- Anything dev-only must be listed in `.distignore` and, if PHP, excluded in `phpcs.xml.dist` and in the `plugin-check` target's `--exclude-*` flags.

## Release

The version lives in three places that must match: the `Version:` header and `OJOBPUB_VERSION` in `ojobpub.php`, and `Stable tag:` in `readme.txt`. Add a `= x.y.z =` changelog entry to `readme.txt`; it becomes the GitHub release notes.

Pushing tag `vX.Y.Z` runs `.github/workflows/release.yml`, which does the following in order:

1. Checks that the tag matches the version.
2. Runs PHPUnit with the schema from GitHub, then PHPCS.
3. Runs `make zip`.
4. Runs Plugin Check (`wordpress/plugin-check-action`) on the unpacked ZIP.
5. Creates a GitHub release with the ZIP attached.
6. Commits the ZIP contents to wordpress.org SVN `trunk/` and copies them to `tags/X.Y.Z/` (separate job `wordpress-org`).

Any failure means no release. Every push to `main` also syncs SVN `trunk/` (and `.wordpress-org/` to `assets/`) via `.github/workflows/wordpress-org.yml`. Both use `bin/svn-deploy.sh` with the secret `SVN_PASSWORD` (user `letsemploy`). Dependabot updates GitHub Actions and Composer dev dependencies weekly.
