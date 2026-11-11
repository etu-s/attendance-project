# Deployment and operations

How to run AMS in production and keep it healthy. Local development is in the
[System Guide](SYSTEM_GUIDE.md) §15. Rules that bind every environment are in [CLAUDE.md](../CLAUDE.md).

## 1. What runs where

| Process | Command | Notes |
| --- | --- | --- |
| Web (PHP-FPM behind nginx) | `public/index.php` | Serves `/api/*` and `/sanctum/*` for every host |
| SPA | static files from `frontend/dist` | Built with `npm ci && npm run build`; served by nginx on the same hosts |
| Queue workers | `php artisan horizon` | Linux only (needs `pcntl`). Processes punches, recalculations, exports, emails |
| Scheduler | `* * * * * php artisan schedule:run` (cron) | close-days (15 min), leave accrual (hourly), export pruning and backups (daily) |
| WebSockets | `php artisan reverb:start` | Live Today board; optional (the board polls without it) |
| Redis | `redis-server` | Required: queues, cache (tenant-tagged), Horizon, rate limits |

Use a process supervisor (systemd or supervisord) for Horizon and Reverb, with automatic restart. After every
deploy run `php artisan horizon:terminate` so workers load the new code.

## 2. Hosts and TLS

- The central console and API answer on the `CENTRAL_DOMAINS` hosts; every workspace on `<subdomain>.<central host>`.
  Use a wildcard DNS record and a wildcard certificate (`*.example.com` and `example.com`).
- Serve everything over HTTPS, then set `SESSION_SECURE_COOKIE=true` and `HSTS_MAX_AGE=31536000`.
- `SANCTUM_STATEFUL_DOMAINS` lists the SPA origins (`example.com,*.example.com`). `SESSION_DOMAIN` stays `null`, so a
  workspace's session cookie is never sent to another workspace.
- `FRONTEND_URL` is the SPA origin (`https://example.com`); emailed links put the workspace subdomain in front.

### nginx (sketch)

```nginx
server {
    listen 443 ssl http2;
    server_name example.com *.example.com;
    root /srv/ams/frontend/dist;

    # The SPA. The API's own security headers are added by Laravel (SecurityHeaders).
    add_header Content-Security-Policy "default-src 'self'; connect-src 'self' wss://ws.example.com; img-src 'self' data:; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'" always;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    location / { try_files $uri /index.html; }

    location ~ ^/(api|sanctum|broadcasting)/ {
        root /srv/ams/public;
        try_files $uri /index.php?$query_string;
    }

    location = /index.php {
        root /srv/ams/public;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        client_max_body_size 6m;   # leave attachments are at most 5 MB
    }
}
```

Reverb gets its own host (`ws.example.com`) proxied to `reverb:start` (port 8080) with WebSocket upgrade headers.

## 3. Environment (`.env`)

Start from `.env.example`; its header lists the production checklist. In short:

- `APP_ENV=production`, `APP_DEBUG=false`, a fresh `APP_KEY` (`php artisan key:generate`), `APP_URL`.
- `LOG_LEVEL=warning`. Personal data and secrets are masked in every log channel (`Core\Logging\MaskSensitiveData`).
- `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_*`.
- A real mailer (`MAIL_MAILER=smtp` or a provider), `MAIL_FROM_ADDRESS`.
- `BROADCAST_CONNECTION=reverb` and `REVERB_*` (plus `VITE_REVERB_*` at build time) for the live Today board.
- `BACKUP_PATH` outside the web root, on a disk that is itself backed up off-site; `BACKUP_KEEP_DAYS`.
- Never commit `.env`.

## 4. First install and deploys

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force          # central schema
php artisan tenants:migrate --force  # every workspace file
php artisan tenants:seed --force     # roles, permissions, new settings (idempotent)
php artisan config:cache && php artisan route:cache && php artisan event:cache
cd frontend && npm ci && npm run build
php artisan horizon:terminate
```

After deploying a release that adds the reports rollup to existing workspaces, run `php artisan reports:rebuild-stats`
once (it queues the work).

Migrations are additive (SQLite cannot alter columns in place). Take a backup before deploying a release with
migrations (`php artisan db:backup`).

## 5. Backups and restore

- `php artisan db:backup` writes `VACUUM INTO` copies of the central DB and every workspace DB to
  `BACKUP_PATH/<timestamp>/` (`central.sqlite`, `tenants/<id>.sqlite`). It is scheduled daily at 02:30; folders older
  than `BACKUP_KEEP_DAYS` (default 14) are deleted afterwards (`--prune`).
- Never copy a live `.sqlite` file (WAL mode): use the command.
- Workspace files (leave attachments, report exports) live in `storage/tenant<id>/app/`; back that directory up with
  your file backups. Exports are disposable; attachments are not.

### Restoring one workspace

1. Suspend the workspace in the console (stops new requests and punches being accepted for it).
2. Stop the queue workers (`php artisan horizon:pause` or stop the service), so no job writes to the file.
3. Move the current file aside: `database/tenants/<id>.sqlite` and its `-wal` / `-shm` files.
4. Copy the backup in place: `cp <BACKUP_PATH>/<timestamp>/tenants/<id>.sqlite database/tenants/<id>.sqlite`.
5. `php artisan tenants:migrate --tenants=<id> --force` (brings an older backup up to the current schema).
6. Resume workers, activate the workspace, then `php artisan attendance:close-days --tenant=<id> --days=31` and
   `php artisan reports:rebuild-stats --tenant=<id>` to recalculate from the raw punches.

Raw punches received after the backup was taken are lost with a restore. Devices that buffer punches can resend them:
ingestion is idempotent, so duplicates are ignored.

### Restoring the central database

Same steps with `database/central.sqlite` and the whole application stopped (it holds tenants, domains and super
admins). Restore the matching workspace files from the same backup folder when in doubt.

## 6. Monitoring

- `GET /api/v1/health` on any workspace host (database check; rate limited).
- Horizon dashboard for queue backlog and failed jobs. A growing `attendance` backlog means punches are arriving
  faster than one writer per workspace can process.
- Logs: failed report exports and failed jobs are logged with masked personal data.
- `composer audit` and `npm audit` in CI.
