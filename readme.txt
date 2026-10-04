=== oJobPub ===
Contributors: letsemploy
Tags: jobs, careers, job board, recruiting, open data
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish your job openings as an open oJobPub feed on your own domain, so job boards and search engines can find them without an API key.

== Description ==

[oJobPub](https://docs.letsemploy.org) is an open JSON format for job openings. An employer publishes one document at a well-known URL on its own domain:

`https://example.com/.well-known/ojobpub.json`

Each entry carries a short, structured summary and links back to the job page on your site, where the full text and the application process stay. Job boards, search engines and tools read the feed directly, or through the aggregated data of [SourceTracker](https://docs.letsemploy.org/resources/sourcetracker/).

This plugin creates and serves that feed from your WordPress content.

= Features =

* **Works with what you have.** Uses your existing listings from WP Job Manager or Job Postings, or a simple built-in "Jobs" post type if you do not use a job plugin yet.
* **Always valid.** Every job is checked against the oJobPub 1.0 rules. Incomplete jobs are left out and listed with the reason on the settings screen instead of breaking the feed.
* **Honest `lastUpdated`.** The timestamp only changes when the published content really changes, including jobs that drop out after their application deadline.
* **Fast.** Cached, with ETag and Last-Modified, so consumers can poll cheaply.
* **Google for Jobs included.** Job pages of the built-in post type also get schema.org JobPosting data.
* **Works on restrictive hosts.** If your server answers `/.well-known/` itself and never hands the request to WordPress, enable the static file option.
* **Self-test.** One click fetches your feed the way a consumer does and reports redirects, wrong content types or stale copies.

The plugin makes no requests to external services. Registering your domain with SourceTracker is a manual, optional step linked from the settings screen.

= For developers =

Source code, issues and pull requests: [github.com/letsemploy/wordpress-plugin-ojobpub](https://github.com/letsemploy/wordpress-plugin-ojobpub)

* `ojobpub_sources` – register adapters for other job plugins or an ATS (implement `OJobPub\Sources\Source`).
* `ojobpub_raw_jobs`, `ojobpub_employer`, `ojobpub_document` – adjust data before or after normalization.
* `ojobpub_wp_job_manager_job`, `ojobpub_wp_job_manager_job_type_map`, `ojobpub_job_postings_job` – tune the mapping of the job plugin adapters.
* `ojobpub_json_ld` – adjust the schema.org output.
* `ojobpub_max_jobs` (default 500), `ojobpub_job_slug` (default `jobs`), `ojobpub_public_url`.

== Installation ==

1. Install and activate the plugin.
2. Go to **Settings → oJobPub**, check the employer details, the default country and the default language.
3. Choose the data source: the built-in Jobs, or WP Job Manager / Job Postings if active.
4. Click **Check feed**.
5. Optional: register your domain with SourceTracker (link on the settings screen).

== Frequently Asked Questions ==

= The check says the feed is not reachable. =

Some hosts answer everything below `/.well-known/` directly from disk (it is used for SSL certificates). Enable **Static file** in the settings; the plugin then keeps a copy of the feed at that path. Alternatively add a redirect or rewrite for `/.well-known/ojobpub.json` to your web server configuration.

= WordPress is installed in a sub-directory. =

The feed must be answered at the root of your domain. Enable the static file option only if the WordPress directory is also the web root; otherwise add a redirect from `/.well-known/ojobpub.json` to `/<sub-directory>/.well-known/ojobpub.json` in your web server.

= My site uses www. =

Consumers ask the apex domain (`example.com`). A redirect from `example.com` to `www.example.com` that keeps the path is fine.

= Why is a job missing from the feed? =

Every job needs a title, language, publication date, job type, at least one location (city or country) and a URL. The settings screen lists jobs that were left out and why. Jobs whose application deadline has passed are removed automatically.

= Does the full job description go into the feed? =

No. The feed carries a plain-text summary of up to 1000 characters (the excerpt, or the beginning of the text) and links to your page.

== Changelog ==

= 0.2.1 =
* Fixed: Job Postings offers saved without a deadline were treated as expired.
* Fixed: Job Postings remote regions such as "Tirol, Österreich" now add their country.
* Fixed: "UK" resolves to the ISO code GB.
* Translations are loaded by WordPress.org language packs.

= 0.2.0 =
* New: adapter for the Job Postings plugin (employment type, address or free-text location, remote countries, salary range and unit, valid-through date, skills as tags).
* New: country names such as "Schweiz" or "Germany" are resolved to ISO codes (all languages with the intl extension, common names without it).
* Improved: WP Job Manager free-text locations like "Berlin, Germany" now carry the country.

= 0.1.0 =
* First version: built-in job post type, WP Job Manager adapter, feed endpoint with caching, static file fallback, self-test, schema.org JobPosting.
