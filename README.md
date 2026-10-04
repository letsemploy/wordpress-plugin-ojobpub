# oJobPub WordPress plugin

Publishes a site's job openings as an [oJobPub](https://docs.letsemploy.org) feed at `/.well-known/ojobpub.json`. Jobs can come from the plugin's own *Jobs* post type, from WP Job Manager or from Job Postings. Other plugins can add sources through the `ojobpub_sources` filter.

`readme.txt` is the user-facing description for wordpress.org. This file covers development.

## Development

Requirements: podman (or docker, with `COMPOSE=docker compose CONTAINER=docker`), make, curl and python3. A local PHP is not needed.

```sh
make up        # WordPress on http://localhost:8080 (admin / admin)
make setup     # install WordPress, WP Job Manager, Job Postings and Plugin Check, activate oJobPub
make seed      # sample jobs in all three sources, including edge cases
make feed      # print the feed
```

The plugin directory is mounted read-only into the container, so code changes are live.

## Checks

```sh
make test          # PHPUnit; validates generated documents against ../schema/v1/ojobpub.json
make phpcs         # WordPress coding standards, PHP 7.4 compatibility
make plugin-check  # wordpress.org Plugin Check, the same checks as the plugin review
make validate-all  # switches through all sources and validates the served feed against the schema
```

## Release

1. Raise the version in `ojobpub.php` (header and `OJOBPUB_VERSION`) and in `readme.txt` (`Stable tag`), then add a changelog entry.
2. Run `make pot` if translatable strings changed.
3. Run `make zip`. It builds `dist/ojobpub-<version>.zip` and leaves out everything listed in `.distignore`.

On GitHub, pushing a tag `v<version>` (e.g. `git tag v0.2.1 && git push origin v0.2.1`) runs [.github/workflows/release.yml](.github/workflows/release.yml):

- It checks that the tag matches all three version fields.
- It runs the unit tests and PHPCS.
- It stores the plugin as the artifact `ojobpub-<version>`. Download it from the run's summary page: the downloaded ZIP is the installable plugin.

## Layout

| Path | Purpose |
|---|---|
| `includes/Feed/` | Normalizer, builder and country codes. No WordPress dependency, unit tested. |
| `includes/Sources/` | `Source` interface and adapters: native, WP Job Manager, Job Postings. |
| `includes/Feed_Service.php` | Caching, invalidation and a `lastUpdated` that only changes when the content changes. |
| `includes/Endpoint.php` | Rewrite rule and HTTP delivery (ETag, 304, CORS). |
| `includes/Static_File.php` | Optional copy on disk, for hosts that serve `/.well-known/` themselves. |
| `bin/seed.php` | Sample data for local testing. Not shipped. |
