# Development tasks. Everything runs in containers; no local PHP needed.

COMPOSE   ?= podman-compose
CONTAINER ?= podman
URL       ?= http://localhost:8080
VERSION   := $(shell sed -n 's/^ \* Version: *//p' ojobpub.php)
SCHEMA    ?= ../schema/v1/ojobpub.json

WP       = $(COMPOSE) run --rm --no-deps -T cli wp
PHP_RUN  = $(CONTAINER) run --rm -v $(CURDIR):/app:Z -w /app docker.io/library/composer:2

.PHONY: help up down reset setup seed wp composer test phpcs plugin-check validate validate-all feed pot zip

help:
	@echo "make up            start WordPress on $(URL)"
	@echo "make setup         install WordPress, job plugins and activate oJobPub"
	@echo "make down          stop containers (data stays)"
	@echo "make reset         stop containers and delete all data"
	@echo "make seed          create sample jobs in all sources"
	@echo "make wp ARGS=...   run a WP-CLI command, e.g. make wp ARGS='plugin list'"
	@echo "make test          unit tests"
	@echo "make phpcs         WordPress coding standards"
	@echo "make plugin-check  wordpress.org Plugin Check"
	@echo "make validate      validate the local feed against the oJobPub schema"
	@echo "make validate-all  validate the feed of every source (native, wp-job-manager, job-postings)"
	@echo "make pot           regenerate languages/ojobpub.pot"
	@echo "make zip           build dist/ojobpub-$(VERSION).zip"

up:
	$(COMPOSE) up -d db wordpress

down:
	$(COMPOSE) down

reset:
	$(COMPOSE) down -v

setup:
	@until $(WP) core is-installed >/dev/null 2>&1 || $(WP) db check >/dev/null 2>&1; do echo "waiting for WordPress..."; sleep 3; done
	$(WP) core is-installed || $(WP) core install --url=$(URL) --title="Example Ltd" \
		--admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
	$(WP) rewrite structure '/%postname%/' --hard
	$(WP) plugin install wp-job-manager job-postings plugin-check
	$(WP) plugin activate ojobpub plugin-check wp-job-manager job-postings
	$(WP) rewrite flush --hard
	@echo "Admin: $(URL)/wp-admin (admin / admin)"
	@echo "Feed:  $(URL)/.well-known/ojobpub.json"

seed:
	$(WP) eval-file wp-content/plugins/ojobpub/bin/seed.php

wp:
	$(WP) $(ARGS)

vendor: composer.json
	$(PHP_RUN) composer install --no-interaction --no-progress
	@touch vendor

composer:
	$(PHP_RUN) composer $(ARGS)

test: vendor
	$(CONTAINER) run --rm -v $(CURDIR):/app:Z -w /app -v $(abspath $(SCHEMA)):/schema.json:ro,Z \
		-e OJOBPUB_SCHEMA=/schema.json docker.io/library/composer:2 vendor/bin/phpunit $(ARGS)

phpcs: vendor
	$(PHP_RUN) vendor/bin/phpcs

plugin-check:
	$(WP) plugin check ojobpub --exclude-directories=vendor,tests,dist,bin --exclude-files=.phpunit.result.cache,compose.yaml,Makefile,composer.json,composer.lock,phpunit.xml.dist,phpcs.xml.dist,.distignore,.gitignore $(ARGS)

feed:
	@curl -fsS $(URL)/.well-known/ojobpub.json

validate:
	@mkdir -p dist
	curl -fsS $(URL)/.well-known/ojobpub.json -o dist/feed.json
	$(CONTAINER) run --rm -v $(CURDIR)/dist:/data:Z -v $(abspath $(SCHEMA)):/schema.json:ro,Z \
		docker.io/library/python:3.13-slim sh -c \
		"pip install -q --root-user-action=ignore check-jsonschema && check-jsonschema --schemafile /schema.json /data/feed.json"

validate-all:
	@for src in native wp-job-manager job-postings; do \
		echo "== $$src"; \
		$(WP) eval '$$s = OJobPub\Settings::all(); $$s["source"] = "'$$src'"; update_option("ojobpub_settings", $$s); OJobPub\Feed_Service::refresh(); $$f = OJobPub\Feed_Service::get(); echo $$f["count"] . " jobs, skipped: " . wp_json_encode($$f["issues"]) . PHP_EOL;' 2>/dev/null; \
		$(MAKE) -s validate 2>&1 | tail -1; \
		cp dist/feed.json dist/feed-$$src.json; \
	done

pot:
	@mkdir -p languages
	$(COMPOSE) run --rm --no-deps -T cli sh -c "wp i18n make-pot wp-content/plugins/ojobpub /tmp/ojobpub.pot --slug=ojobpub --domain=ojobpub --exclude=vendor,tests,dist,bin >&2 && cat /tmp/ojobpub.pot" > languages/ojobpub.pot

zip:
	@rm -rf dist/ojobpub dist/ojobpub-$(VERSION).zip
	@mkdir -p dist/ojobpub
	rsync -a --exclude-from=.distignore ./ dist/ojobpub/
	cd dist && python3 -m zipfile -c ojobpub-$(VERSION).zip ojobpub && rm -rf ojobpub
	@echo dist/ojobpub-$(VERSION).zip
