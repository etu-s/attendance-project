# Running AMS with Docker

The whole stack (API, SPA, queue workers, scheduler, WebSockets, Redis) runs from `docker/`. Production concerns
that apply to every install (TLS, backups, restore, monitoring) are in the [deployment guide](deployment.md).

## Quick start

Docker Desktop (or Docker Engine) with Compose v2. Run every command from the `docker/` folder, so Compose picks up
`docker/.env`:

```bash
cd docker
cp .env.example .env
docker compose up -d --build
```

- Central console: http://localhost:8080 (`admin@ams.test` / `password`)
- Demo workspace: http://acme.localhost:8080 (`admin@acme.test` / `password1`)

The demo accounts and the `acme` workspace exist only with `APP_ENV=local`, the default in `docker/.env.example`.
`*.localhost` resolves to your machine in Chrome, Edge and Firefox without editing the hosts file.

The first start builds both images (a few minutes) and runs `init`, which migrates and seeds before anything else
starts. `docker compose ps` shows `init` as exited (0) once it is done.

## What runs

| Service | Image | Command | Role |
| --- | --- | --- | --- |
| `init` | `ams-app` | `init` (one-shot) | `migrate --seed`, `tenants:migrate`, `tenants:seed` (all idempotent), then exits |
| `app` | `ams-app` | `php-fpm` | The Laravel API |
| `horizon` | `ams-app` | `php artisan horizon` | Queue workers: punches, recalculation, exports, emails |
| `scheduler` | `ams-app` | `php artisan schedule:work` | close-days, leave accrual, export pruning, daily backups |
| `reverb` | `ams-app` | `php artisan reverb:start` | WebSockets for the live Today board |
| `web` | `ams-web` | nginx | The built SPA; `/api`, `/sanctum`, `/broadcasting`, `/horizon`, `/up` → php-fpm; `/app` → Reverb |
| `redis` | `redis:7.4-alpine` | `redis-server --appendonly yes` | Queues, tenant-tagged cache, rate limits |

`app`, `horizon`, `scheduler` and `reverb` start only after `init` succeeds. Every PHP container runs as `www-data`;
the application code in the image is read-only to it.

## Data and volumes

| Volume | Mounted at | Holds |
| --- | --- | --- |
| `ams_data` | `/var/www/data` | `central.sqlite`, `tenants/<id>.sqlite` (+ WAL/SHM), `app.key` |
| `ams_storage` | `/var/www/html/storage` | Logs, sessions, workspace files (`tenant<id>/`: leave attachments, report exports) |
| `ams_backups` | `/var/www/backups` | `db:backup` output (`VACUUM INTO`), one folder per run |
| `ams_redis` | `/data` | Redis append-only file |

Keep the databases on a named volume. SQLite in WAL mode needs a real Linux filesystem; a bind mount from Windows or
macOS breaks its locking.

`APP_KEY` left empty in `docker/.env` is generated on the first start and stored as `app.key` on the data volume,
beside the data it encrypts. To manage the key yourself, set `APP_KEY` (`base64:…`) before the first start and keep
it: changing it later invalidates sessions and encrypted values.

`docker compose down` keeps the volumes. `docker compose down -v` deletes them, and with them every database.

## Everyday commands

```bash
docker compose ps
docker compose logs -f app horizon
docker compose exec app php artisan about
docker compose exec app php artisan punches:simulate --tenant=acme
docker compose exec app php artisan db:backup
docker compose exec app php artisan reports:rebuild-stats
docker compose up -d --build          # after pulling new code: rebuild, re-run init (migrations), restart
```

Copy a backup out of the stack (never copy a live `.sqlite` file):

```bash
docker compose exec app php artisan db:backup
docker compose cp app:/var/www/backups ./backups
```

## Configuration

`docker/.env` is both the containers' environment and the source of the values Compose interpolates. Database paths,
Redis, the queue and cache drivers, the internal Reverb address and stderr logging are fixed in `compose.yaml`;
everything else from the root `.env.example` can be added to `docker/.env`.

| Setting | Default | Notes |
| --- | --- | --- |
| `AMS_HTTP_PORT` | `8080` | Host port nginx is published on |
| `AMS_DOMAIN` | `localhost` | Central host; workspaces are `<subdomain>.<AMS_DOMAIN>` |
| `AMS_SCHEME` | `http` | Used for the derived URLs and the WebSocket scheme |
| `AMS_PUBLIC_PORT` | `AMS_HTTP_PORT` | Port browsers use, when a TLS proxy in front listens elsewhere (443) |
| `APP_URL`, `FRONTEND_URL`, `CENTRAL_DOMAINS`, `SANCTUM_STATEFUL_DOMAINS` | derived from the three above | Set them to override |
| `REVERB_APP_KEY` / `REVERB_APP_SECRET` | demo values | Change the secret for any shared install |

Every PHP container caches config, routes and events when it starts (that cache is also how `docker compose exec`
commands get the generated `APP_KEY`). After editing `docker/.env`, run `docker compose up -d` to recreate them.

The SPA is built with `CENTRAL_DOMAINS`, `REVERB_APP_KEY`, the public port and the scheme baked in. After changing
any of them, rebuild the web image: `docker compose up -d --build web`.

## Production on Docker

1. In `docker/.env`: `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`, a real mailer, a fresh
   `REVERB_APP_SECRET`. Outside `local` no demo data is seeded and the Horizon dashboard follows the `viewHorizon`
   gate.
2. Put a TLS proxy (Caddy, Traefik, a load balancer) with a wildcard certificate in front of `web`, set
   `AMS_SCHEME=https`, `AMS_DOMAIN=<your domain>`, `AMS_PUBLIC_PORT=443` and the URL overrides without a port, then
   `SESSION_SECURE_COOKIE=true` and `HSTS_MAX_AGE=31536000`. nginx passes the client address to PHP unchanged; a
   proxy in front must be reflected in Laravel's trusted-proxy settings, or rate limits and IP allowlists see the
   proxy's address.
3. Copy the `ams_backups` volume off the host regularly ([deployment guide](deployment.md) §5 for restores; use
   `docker compose stop horizon scheduler` where it says to stop the workers).

Production seeding creates only the plans. There is no command yet to create the first super admin outside
`APP_ENV=local`.
