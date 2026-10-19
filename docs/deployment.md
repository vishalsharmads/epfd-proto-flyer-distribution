# Deploying EPFD

This runbook covers a first production deploy and the routine after it. It assumes Docker, but the steps map directly onto any host that runs PHP 8.4.

## What runs

There is one image (`docker/production/Dockerfile`) and three roles, which differ only in their command:

| Role | Command | Notes |
|---|---|---|
| web | default (nginx + php-fpm on `:8080`) | Put it behind a TLS-terminating load balancer. Scale it horizontally. |
| worker | `php artisan queue:work --tries=3 --max-time=3600` | Sends invoice and proof emails. Run at least one. |
| scheduler | `php artisan schedule:work` | Runs backups and housekeeping (`routes/console.php`). Run exactly one. |

Supporting services:

- **PostgreSQL 16+ with PostGIS.** Managed is best.
- **Redis.** Used for cache, sessions and queues.
- **A persistent volume** shared by web and worker at `storage/app` (uploads).

`docker-compose.production.yml` shows the whole layout.

## First deploy

1. **Environment.** Fill in `.env.production.example` in your host's secret store. You must set `APP_KEY` (from `php artisan key:generate --show`), `APP_URL`, `EPFD_ABN`, the database, the mail settings and `TRUSTED_PROXIES`.
2. **Build.** Run `docker build -f docker/production/Dockerfile --build-arg VITE_SENTRY_DSN=… -t epfd:<version> .`
3. **Database.** Enable PostGIS (`CREATE EXTENSION postgis;`). Then start the web role with `RUN_MIGRATIONS=true`, or run `php artisan migrate --force` once.
4. **Base data.** Run `php artisan db:seed --force`. In production this creates roles, catalogue and content only. Demo accounts are never seeded there.
5. **First admin.** Run `php artisan epfd:make-admin you@epfd.com.au --role=super_admin`. It prompts for a password: at least 12 characters, mixed case and a number.
6. **G-NAF addresses.** Mount the unzipped release at `GNAF_PATH`, then run `php artisan gnaf:import`. Repeat this each quarter.
7. **Payments.**
   - In the Stripe dashboard, add the webhook endpoint `https://<APP_URL>/api/v1/webhooks/stripe` with these events: `payment_intent.succeeded`, `payment_intent.payment_failed` and `charge.refunded`.
   - Create that endpoint with API version `2026-09-30.endive`, the same version the app pins (`STRIPE_API_VERSION`). Webhook payloads use the endpoint's version, not the app's.
   - Open Admin → Payment gateways and enter the publishable key, secret key and webhook signing secret. Alternatively, set `STRIPE_*` in the environment.
   - Click **Test connection**.
   - Open Admin → Settings and set *Payment gateway* to Stripe. It won't switch until the connection test passes.
8. **Check.**
   - `GET /up` should return 200 (liveness).
   - `GET /health` should return 200 with every check `ok` (readiness: database, cache, storage, queue, payments). Point uptime monitoring at `/health`.

## Every deploy

1. Build and push a new image tag.
2. Roll the web role first. Only one service should have `RUN_MIGRATIONS=true`.
3. Then restart worker and scheduler.

The entrypoint rebuilds config, route, view and event caches from the environment on every start, so a change to the environment only needs a restart.

## Payments: how it behaves

- **Card data.** Card numbers are collected by Stripe's Payment Element in the browser. The server only sees `pm_…` ids.
- **3-D Secure.** Some cards need authentication. The checkout records a payment with status `requires_action`, the browser completes the bank check, and then the payment is confirmed. If the customer closes the tab, the Stripe webhook completes the order instead.
- **Abandoned payments.** Payments still awaiting authentication after 24 hours are closed by `payments:expire-abandoned`. A late success webhook still completes the order.
- **Refunds.** Refunds always go back through the gateway that took the payment, even after switching gateways.
- **Mock gateway.** The mock gateway (test cards) is refused in production. `/health` reports `payments: fail` if the active gateway can't be used.

## Backups

- **Schedule.** `backup:run` runs nightly at 01:30 Sydney time. It saves the database plus `storage/app` (uploads).
- **G-NAF.** G-NAF rows (about 10 GB) are left out because they can be re-imported.
- **Storage.** Set `BACKUP_DISKS` to an off-site disk and `BACKUP_ARCHIVE_PASSWORD` to encrypt the zip.
- **Alerts.** Failures and stale backups are emailed to `BACKUP_NOTIFY_EMAIL`.
- **Restore.**
  1. Unzip the archive.
  2. Run `psql < db-dumps/postgresql-*.sql` into an empty PostGIS database.
  3. Restore `storage/app`.
  4. Re-run `gnaf:import`.

If `pg_dump` and the server differ in major version, point `DB_DUMP_BINARY_PATH` at matching client tools.

## Monitoring

- **Errors.** Set `SENTRY_LARAVEL_DSN` for server errors and the `VITE_SENTRY_DSN` build argument for browser errors.
- **Logs.** Logs go to stderr (`LOG_CHANNEL=stderr`). Collect them from the container runtime.
- **Uptime.** Monitor `/health`. It returns 503 when the database, cache, storage, queue backlog (over 10 minutes) or payment gateway fails.
