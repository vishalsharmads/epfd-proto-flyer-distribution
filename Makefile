# EPFD — developer commands.
# Uses Docker Compose when `docker` is available; otherwise runs natively
# (Homebrew PostgreSQL 17 + PostGIS on port 5433, Laravel Herd at http://epfd.test, Mailpit).

SHELL := /bin/bash
DOCKER := $(shell command -v docker 2>/dev/null)
PG_BIN ?= /opt/homebrew/opt/postgresql@17/bin
PG_DATA ?= /opt/homebrew/var/postgresql@17
PG_PORT ?= 5433
STATES ?= NSW,VIC
BOUNDARIES_PATH ?= $(shell grep -E '^BOUNDARIES_PATH=' .env 2>/dev/null | cut -d= -f2- | tr -d '"')
GNAF_RELEASE_LABEL ?= $(shell grep -E '^GNAF_RELEASE_LABEL=' .env 2>/dev/null | cut -d= -f2- | tr -d '"')
ifdef DOCKER
  RUN := docker compose exec -T app
else
  RUN :=
endif

.PHONY: up down seed gnaf boundaries gnaf-sample-extract bench test test-php test-e2e lint fix build dev mail db-native

## up: start the stack, install deps, build assets, migrate
up:
ifdef DOCKER
	docker compose up -d --build
	$(RUN) composer install --no-interaction
	$(RUN) php artisan key:generate --force --no-interaction || true
	$(RUN) php artisan migrate --force
else
	@$(MAKE) --no-print-directory db-native mail
	composer install --no-interaction
	npm ci --legacy-peer-deps
	@test -f .env || (cp .env.example .env && php artisan key:generate)
	npx vite build
	php artisan migrate --force
	-herd link epfd >/dev/null 2>&1 && echo "→ http://epfd.test"
endif

## db-native: start local PostgreSQL 17 + PostGIS on :5433 and create databases
db-native:
	@$(PG_BIN)/pg_isready -q -p $(PG_PORT) || ( [ -d $(PG_DATA) ] || LC_ALL=en_US.UTF-8 $(PG_BIN)/initdb -D $(PG_DATA) --encoding=UTF8 >/dev/null; \
		LC_ALL=en_US.UTF-8 $(PG_BIN)/pg_ctl -D $(PG_DATA) -o "-p $(PG_PORT) -c shared_buffers=1GB -c work_mem=64MB -c maintenance_work_mem=1GB -c max_wal_size=8GB" -l $(PG_DATA)/server.log -w start )
	@for db in epfd epfd_test; do \
		$(PG_BIN)/psql -p $(PG_PORT) -d postgres -tAc "select 1 from pg_database where datname='$$db'" | grep -q 1 || $(PG_BIN)/createdb -p $(PG_PORT) $$db; \
		$(PG_BIN)/psql -p $(PG_PORT) -d $$db -qc "create extension if not exists postgis; create extension if not exists pg_trgm;"; \
	done
	@echo "PostgreSQL + PostGIS ready on :$(PG_PORT)"

## mail: start Mailpit (SMTP :1025, UI http://127.0.0.1:8025)
mail:
	@command -v mailpit >/dev/null || brew install mailpit
	@pgrep -x mailpit >/dev/null || (nohup mailpit >/dev/null 2>&1 &) ; echo "Mailpit → http://127.0.0.1:8025"

down:
ifdef DOCKER
	docker compose down
else
	-$(PG_BIN)/pg_ctl -D $(PG_DATA) stop
	-pkill -x mailpit
endif

## seed: fresh schema + real G-NAF SAMPLE (no download needed) + demo data
seed:
	$(RUN) php artisan migrate:fresh --force
	$(RUN) php artisan gnaf:import --sample
	$(RUN) php artisan db:seed --force

## gnaf: import the full release (default NSW,VIC) from GNAF_PATH. STATES=NSW,VIC,QLD to override.
gnaf:
	$(RUN) php artisan gnaf:import --states=$(STATES)

## boundaries: official suburb boundaries from Geoscape Admin Boundaries (BOUNDARIES_PATH = unzipped LOCALITIES_* folder, same release as G-NAF)
boundaries:
	@test -n "$(BOUNDARIES_PATH)" || (echo "Set BOUNDARIES_PATH to the unzipped LOCALITIES_*_GDA2020_SHP_* folder (see Admin → G-NAF data status → Import guide)"; exit 1)
	$(RUN) php artisan suburbs:import $(foreach s,$(shell echo $(STATES) | tr ',' ' ' | tr 'A-Z' 'a-z'),"$(BOUNDARIES_PATH)/$(s)_localities.shp") --source="Geoscape $(GNAF_RELEASE_LABEL)"

## gnaf-sample-extract: rebuild database/gnaf-sample from the full release
gnaf-sample-extract:
	scripts/make-gnaf-sample.sh "$$GNAF_PATH"

## bench: estimator performance proof (5,000-letterbox polygon < 1 s)
bench:
	$(RUN) php artisan gnaf:benchmark

## test: Pest + Playwright
test: test-php test-e2e

test-php:
	$(RUN) php artisan config:clear
	$(RUN) ./vendor/bin/pest

test-e2e:
	npx playwright test

## lint: Pint + ESLint + TypeScript
lint:
	./vendor/bin/pint --test
	npx eslint resources/js tests/e2e
	npx tsc --noEmit

fix:
	./vendor/bin/pint
	npx eslint --fix resources/js tests/e2e
	npx prettier --write "resources/js/**/*.{ts,tsx}"

build:
	npx vite build

dev:
	npx vite
