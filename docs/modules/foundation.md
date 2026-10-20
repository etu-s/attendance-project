# Module 1 — Foundation

Status: **approved** (2026-10-20)

The platform skeleton every later module plugs into: multi-tenancy (one SQLite file per tenant),
super admin console, tenant authentication, roles and permissions, typed tenant settings, audit
logging, backups, and the React SPA shell.

## Architecture

```
app/Modules/
  Core/      shared kernel: module loader, tenancy DB manager, settings, audit log,
             permission catalog, backups, session scoping, health
  Central/   central DB: plans, tenants, domains, super admins, tenant provisioning
  Identity/  tenant DB: users, roles/permissions (spatie), tenant auth
frontend/    React SPA (one build, two apps chosen by hostname)
```

Each module extends `Core\Support\ModuleServiceProvider`, which auto-wires:

| Folder / file                  | Wired to                                             |
| ------------------------------ | ---------------------------------------------------- |
| `Database/Migrations/central/` | `php artisan migrate` (central DB)                   |
| `Database/Migrations/tenant/`  | `php artisan tenants:migrate` (every tenant DB)      |
| `routes/central.php`           | `/api/v1/central/*`, central hosts only, `central.*` |
| `routes/tenant.php`            | `/api/v1/*`, tenant subdomains only, `tenant.*`      |
| `Tests/{Unit,Feature}`         | Pest (`composer test`)                               |

Modules talk through services and events only:

- `Core\Events\TenantDefaultsSyncRequested`: idempotent per-tenant defaults (Identity syncs roles
  and permissions, Core seeds missing settings). Fired at provisioning and by `php artisan tenants:seed`.
- `Core\Events\TenantDatabaseProvisioned`: one-time setup of a new tenant (Core applies the chosen
  timezone, Identity creates the first Tenant Admin).
- `Core\Permissions\PermissionCatalog` and `Core\Settings\SettingsRegistry` are where a module
  declares its permissions and settings in its service provider.

## Data model

**Central DB** (`database/central.sqlite`)

| Table          | Notes                                                                                     |
| -------------- | ----------------------------------------------------------------------------------------- |
| `plans`        | `slug` unique, `max_employees` null = unlimited, `features` JSON, `is_active`               |
| `tenants`      | UUID `id` (= DB file name), `name`, `plan_id` FK restrict, `status` (provisioning/active/suspended), `data` (stancl internals) |
| `domains`      | subdomain label only (`acme`), unique, FK cascade to tenant                               |
| `super_admins` | `email` NOCASE unique, separate `central` guard                                           |
| `audit_logs`   | central changes (tenants, plans, domains, super admins)                                   |
| `jobs`, `job_batches`, `failed_jobs` | queue bookkeeping                                                   |

**Tenant DB** (`database/tenants/<uuid>.sqlite`)

| Table                       | Notes                                                                  |
| --------------------------- | ---------------------------------------------------------------------- |
| `users`                     | `email` NOCASE unique (incl. soft-deleted), `status`, soft deletes     |
| spatie permission tables    | roles: tenant-admin, hr-manager, department-manager, employee          |
| `personal_access_tokens`    | Sanctum; reserved for devices/integrations                             |
| `settings`                  | `key` unique, JSON-encoded scalar `value`                              |
| `audit_logs`                | same schema as central (`AuditLogSchema`)                              |

Every connection runs `journal_mode=WAL`, `foreign_keys=ON`, `busy_timeout=5000`,
`synchronous=NORMAL`, `transaction_mode=IMMEDIATE` (config/database.php; verified by `SqlitePragmasTest`).

## Business rules

- **Provisioning** (`TenantProvisioner`) is synchronous: a short central transaction creates the tenant and
  domain rows; the SQLite file, migrations and module listeners run outside it. Any failure deletes the
  tenant and its file. Typical duration: about 1 to 3 s.
- **Subdomains** are 2 to 63 lowercase letters, digits or inner hyphens. Reserved names (`config/ams.php`) are
  refused. A subdomain is immutable after creation.
- **Tenant lifecycle:** active → suspended (tenant routes 403) → active. Delete only when suspended, with
  the subdomain typed as confirmation; a `VACUUM INTO` backup is written first.
- **Plans** in use cannot be deleted (409 `plan_in_use`); deactivate them instead.
- **Users** hold exactly one role. Only a Tenant Admin may grant, revoke or edit the Tenant Admin role
  or an admin account. Nobody can change their own role or status or delete themselves. A tenant always
  keeps at least one *active* Tenant Admin (409 `last_tenant_admin`). Deleting a user soft-deletes it and
  revokes its API tokens.
- **Default permissions:** Tenant Admin = everything; HR Manager = users.view/create/update,
  settings.view, audit-logs.view; Department Manager and Employee = none yet (later modules add theirs).
- **Settings** (`general.timezone`, `general.date_format`, `general.time_format`, `general.week_start`) are
  validated against their definitions. Defaults are copied into each tenant at provisioning; new
  definitions reach existing tenants via `tenants:seed` without overwriting values.
- **Audit log** records create/update/delete/restore on audited models plus `role_changed`. It records
  the actor (user / super admin / system), IP and user agent. Passwords are stored as `[redacted]`, and noise
  (timestamps, `last_login_at`, remember tokens) is skipped. Entries are append-only, and a tenant's
  entries live in that tenant's DB.

## Security and isolation

- Tenancy by subdomain (stancl/tenancy v3). Unknown subdomains → 404 `Workspace not found.` (never names
  the domain). Central routes 404 on tenant hosts and tenant routes 404 on central hosts.
- **Sessions are bound to their tenant** (`ScopeSessionToTenant`). Sessions are stored outside tenant DBs and
  the guard stores only a user id, so a cookie replayed on another tenant would otherwise log in as that
  tenant's user with the same id. A mismatched session is invalidated and its resolved guard user dropped.
  Cookies are also host-only (no `SESSION_DOMAIN`), so browsers never send them across subdomains.
- **spatie permission cache** is keyed per tenant (`ScopePermissionCacheToTenant`). The package resolves its
  cache via `store()`, which stancl's tagged cache manager does not tag.
- Settings cache keys include the tenant id on top of tenancy cache tags.
- Login is rate limited per host + email + IP (5/min), and the API per host + user (120/min). Both are configurable in
  `config/ams.php` and `.env`.
- Exception output for clients: production must run with `APP_DEBUG=false`; with debug on, Laravel
  returns exception messages that can include file paths (and so tenant ids).

## Running it

```bash
composer setup                       # install, .env, key, central.sqlite, migrate --seed (demo tenant in local)
php artisan serve                    # API on :8000
cd frontend && npm install && npm run dev   # SPA on :5173 (proxies /api and /sanctum to :8000)
```

- Super admin console: http://localhost:5173 (`admin@ams.test` / `password`, from `.env` `SEED_*`)
- Demo tenant: http://acme.localhost:5173 (`admin@acme.test` / `password1`)
- `*.localhost` resolves to 127.0.0.1 in modern browsers, so no hosts-file edits are needed.

Different ports: set `VITE_API_PROXY_TARGET` in `frontend/.env.local`, run Vite with `--port`, and add the
origin to `SANCTUM_STATEFUL_DOMAINS`.

Queues: `QUEUE_CONNECTION=redis` with `php artisan horizon` on Linux. On Windows (no `pcntl`) use
`php artisan queue:work`. Without Redis, local dev can use `CACHE_STORE=array`, `CACHE_LIMITER=file`,
`QUEUE_CONNECTION=database` (see `.env.example`).

## Commands

| Command                                | Purpose                                                        |
| -------------------------------------- | -------------------------------------------------------------- |
| `php artisan migrate --seed`           | central schema, plans; in `local` also super admin + `acme`    |
| `php artisan tenants:migrate`          | run tenant migrations on every tenant DB                       |
| `php artisan tenants:seed --force`     | re-sync roles/permissions and missing settings for all tenants |
| `php artisan db:backup [--tenant=ID]`  | `VACUUM INTO` backups → `storage/backups/<timestamp>/`         |
| `composer test`                        | Pest (126 tests)                                               |

## Tests

126 Pest tests / 437 assertions in `app/Modules/*/Tests`. Highlights:

- `TenantIsolationTest`: separate files; tenant A's raw SQLite file contains none of B's users,
  settings or audit rows; no tenant tables in central; API lists only the current tenant; session replay
  across tenants refused (including the identical-password-hash case, which isolates `ScopeSessionToTenant`);
  per-tenant permission caches; host separation for central/tenant routes.
- `SqlitePragmasTest`: pragmas on real central and tenant files.
- `TenantProvisionerTest`: full rollback when a module listener fails.
- Feature tests for every endpoint; unit tests for the catalog, registry and setting types.

Tenant DBs in tests are real files in `storage/framework/testing/tenants-<pid>`, removed after every test.
The central DB is in-memory.

## Frontend

`frontend/` = React 19 + TypeScript (strict) + Vite 8 + Tailwind 4 + shadcn/ui, TanStack Query,
React Router 8, React Hook Form + Zod 4.

- `src/lib/host.ts` picks the app from the hostname (central console vs tenant workspace). Each app is
  lazy-loaded, so tenants never download the console.
- `src/lib/api/client.ts`: same-origin fetch client, Sanctum CSRF handshake, retries a 419 once, typed
  `ApiError` with field errors and business `rule`.
- Every list uses `DataTable` + `useListParams` (server-side page/sort/search/filters kept in the URL),
  with skeleton, empty and error states.
- Forms mirror backend validation in Zod; server 422s are mapped back onto fields.
- `useCan()` hides actions the user lacks. The API enforces the same rules regardless.
- Light/dark/system theme (next-themes), responsive shell with a mobile sheet nav, skip link, labelled
  controls, visible focus rings.

## Known limitations / follow-ups

- **Reverb** is installed but not configured: no broadcasting yet. It will be wired when a module needs
  real-time updates (Punch Ingestion).
- **Horizon** cannot run on Windows (`ext-pcntl`). `composer.json` declares `ext-pcntl`/`ext-posix` under
  `config.platform` so installs succeed on Windows; use `queue:work` locally.
- No password reset / invitation email flow yet (`password_reset_tokens` exists); admins set passwords.
- No restore endpoint for soft-deleted users; their email stays reserved.
- Central audit log is recorded but has no API/UI yet.
- Plan limits (`max_employees`, `features`) are stored, not enforced. Enforcement belongs to the owning
  modules (Employees, ...).
- No frontend test runner (none in the Definition of Done); quality gates are `tsc`, ESLint, the build,
  and manual browser checks.
- OpenAPI spec (`docs/api/openapi.yaml`) is hand-maintained; keep it in sync with routes and resources.
