# AMS — System Guide for Engineers

A single document that explains how the Attendance Management System (AMS) is built, how it works, and how
to extend it safely. Read it once end to end before your first change, then use it as a reference.

> **Source of truth.** [CLAUDE.md](../CLAUDE.md) holds the binding project rules, and the module docs in
> [docs/modules](modules) hold the detailed decisions per module. This guide summarises and connects them.
> If something here disagrees with those files or with the code, the code and CLAUDE.md win; please fix
> this guide.
>
> Last reviewed: 2026-11-11 (Modules 1–8 approved, Module 9 built and awaiting approval).

---

## Contents

1. [What the system does](#1-what-the-system-does)
2. [Status and roadmap](#2-status-and-roadmap)
3. [Tech stack](#3-tech-stack)
4. [Repository layout](#4-repository-layout)
5. [Backend architecture: the modular monolith](#5-backend-architecture-the-modular-monolith)
6. [Multi-tenancy: how a request finds its tenant](#6-multi-tenancy-how-a-request-finds-its-tenant)
7. [Database conventions (SQLite)](#7-database-conventions-sqlite)
8. [Time and dates](#8-time-and-dates)
9. [Authentication, roles and permissions](#9-authentication-roles-and-permissions)
10. [Backend request conventions](#10-backend-request-conventions)
11. [Cross-cutting services: settings, audit log, backups](#11-cross-cutting-services-settings-audit-log-backups)
12. [Feature catalog by module](#12-feature-catalog-by-module)
13. [Planned modules and their domain rules](#13-planned-modules-and-their-domain-rules)
14. [Frontend architecture](#14-frontend-architecture)
15. [Local development](#15-local-development)
16. [Testing](#16-testing)
17. [How-to recipes](#17-how-to-recipes)
18. [Workflow, quality gates and Git](#18-workflow-quality-gates-and-git)
19. [Known limitations and open follow-ups](#19-known-limitations-and-open-follow-ups)
20. [Glossary](#20-glossary)

---

## 1. What the system does

AMS is a **multi-tenant SaaS** for employee attendance. Each customer company is a *tenant* with its own
subdomain (`acme.example.com`) and its own SQLite database file. A central *super admin console* manages
tenants and subscription plans.

Inside a tenant workspace, the product covers (built or planned):

- Organization structure: profile, locations, nested departments, groups, employees.
- Work-time definitions: timetables (fixed or flexible work periods with breaks), shifts (repeating
  day/week cycles of timetables).
- Scheduling: which shift applies to which department, group or employee, plus temporary schedules and
  holidays.
- **Punch ingestion**: external devices and systems post raw clock-in/out timestamps to an API. Punches are
  stored immediately (append-only) and processed asynchronously by an attendance engine that evaluates them
  against each employee's resolved schedule.
- Leave (ledger-based balances), overtime (calculated, then approved), reports and analytics.

Design goals: modular, strictly tenant-isolated, recalculable (attendance can always be rebuilt from raw
punches + schedule + rules), and a modern, simple UI.

---

## 2. Status and roadmap

Modules are built **one at a time, in order**, and each needs explicit approval from the product owner
before the next starts (see [§18](#18-workflow-quality-gates-and-git)).

| #   | Module                              | Status                                           | Doc                                              |
| --- | ----------------------------------- | ------------------------------------------------ | ------------------------------------------------ |
| 1   | Foundation                          | Approved 2026-10-20                              | [foundation.md](modules/foundation.md)           |
| 2   | Organization & Employees            | Approved 2026-10-21                              | [organization.md](modules/organization.md)       |
| 3   | Timetable & Shifts                  | Approved 2026-10-21                              | [timetable.md](modules/timetable.md)             |
| 4   | Scheduling                          | Approved 2026-10-21                              | [scheduling.md](modules/scheduling.md)           |
| 5   | Punch Ingestion & Attendance Engine | Approved 2026-10-21                              | [attendance.md](modules/attendance.md)           |
| 6   | Leave Management                    | Approved 2026-10-27                              | [leave.md](modules/leave.md)                     |
| 7   | Overtime Management                 | Approved 2026-10-28                              | [overtime.md](modules/overtime.md)               |
| 8   | Reports & Analytics                 | Approved 2026-11-10                              | [reports.md](modules/reports.md)                 |
| 9   | Hardening                           | Built, awaiting approval (2026-11-11)            | [hardening.md](modules/hardening.md)             |

The status table in [CLAUDE.md](../CLAUDE.md) and [docs/README.md](README.md) is updated after every approval.
Change history is in [CHANGELOG.md](../CHANGELOG.md).

---

## 3. Tech stack

### Backend

| Concern            | Choice                                                                    |
| ------------------ | ------------------------------------------------------------------------- |
| Language/framework | PHP 8.3+ (8.4 locally), **Laravel 13**                                    |
| Database           | **SQLite only**, WAL mode. One central file + one file per tenant         |
| Tenancy            | `stancl/tenancy` v3, database-per-tenant, subdomain identification        |
| Auth               | Laravel Sanctum (cookie-based SPA sessions; bearer tokens reserved for devices/integrations) |
| Permissions        | `spatie/laravel-permission` v8, driven by a module permission catalog     |
| Queues             | Redis + Horizon in production; `queue:work` with the database driver locally on Windows |
| Cache              | Redis (tenancy tags cache entries per tenant)                             |
| Real time          | Laravel Reverb: live Today board on a private per-workspace channel (optional; the board polls every 30 s without it) |
| Tests              | Pest 5                                                                    |
| Style              | Laravel Pint, `declare(strict_types=1)`, typed properties and returns     |

### Frontend (`/frontend`)

| Concern       | Choice                                                         |
| ------------- | -------------------------------------------------------------- |
| Framework     | React 19 + TypeScript 6 (strict) + Vite 8                      |
| Styling / UI  | Tailwind CSS 4 + shadcn/ui (Radix), lucide icons, sonner toasts|
| Server state  | TanStack Query 5                                               |
| Routing       | React Router 8                                                 |
| Forms         | React Hook Form + Zod 4 (schemas mirror backend validation)    |
| Theme         | next-themes (light / dark / system)                            |
| Charts        | Recharts (Reports dashboard)                                   |
| Lint / format | ESLint (zero warnings) + Prettier (with Tailwind plugin)       |

Adding a new **major** dependency (backend or frontend) needs approval first.

---

## 4. Repository layout

```
attendance project/
├── CLAUDE.md                 Binding project rules (read first)
├── README.md                 Quick start
├── CHANGELOG.md              Keep-a-Changelog, one entry per module
├── app/
│   ├── Modules/              ← all business code lives here (one folder per module)
│   │   ├── Core/             shared kernel (module loader, tenancy DB, settings, audit, permissions, backups)
│   │   ├── Central/          central DB: plans, tenants, domains, super admins, provisioning
│   │   ├── Identity/         tenant users, roles/permissions, tenant auth
│   │   ├── Organization/     profile, locations, departments, groups, employees, CSV import
│   │   └── Timetable/        timetables, breaks, shifts, ShiftCalendar (Module 3, in progress)
│   └── Providers/            framework-level providers only (Tenancy, Horizon)
├── bootstrap/
│   ├── app.php               middleware groups ("central", "tenant"), JSON exception rendering
│   └── providers.php         registers every module's ServiceProvider (add new modules here)
├── config/
│   ├── ams.php               app-level config: reserved subdomains, pagination, backups, rate limits, seed accounts
│   ├── database.php          central + tenant SQLite connections with pragmas
│   └── tenancy.php           stancl config; tenant migration paths are filled in at boot
├── database/
│   ├── central.sqlite        central DB (git-ignored)
│   └── tenants/<uuid>.sqlite one file per tenant (git-ignored)
├── docs/
│   ├── SYSTEM_GUIDE.md       this file
│   ├── README.md             module status index
│   ├── modules/*.md          per-module decisions, data model, rules, endpoints
│   └── api/openapi.yaml      hand-maintained OpenAPI spec (v0.2.0 at Module 2)
├── frontend/                 React SPA (see §14)
├── storage/backups/          VACUUM INTO backups (db:backup)
└── tests/                    Pest bootstrap only; real tests live in app/Modules/*/Tests
```

### Anatomy of a module

Every module follows the same shape. Folders it does not need are simply absent.

```
app/Modules/<Name>/
├── <Name>ServiceProvider.php     extends Core\Support\ModuleServiceProvider
├── Database/
│   ├── Migrations/central/       → php artisan migrate          (central DB)
│   ├── Migrations/tenant/        → php artisan tenants:migrate  (every tenant DB)
│   ├── Factories/
│   └── Seeders/
├── Enums/                        PHP backed enums for statuses and types
├── Events/  Listeners/  Jobs/
├── Http/
│   ├── Controllers/              thin: call a service, return a Resource
│   ├── Requests/                 validation + authorize()
│   └── Resources/                JSON shape of every response
├── Models/
├── Policies/
├── Services/                     business logic (the only public surface for other modules)
├── Support/                      value objects
├── routes/
│   ├── central.php               → /api/v1/central/*   (central hosts only)
│   └── tenant.php                → /api/v1/*           (tenant subdomains only)
└── Tests/{Unit,Feature}/
```

---

## 5. Backend architecture: the modular monolith

### 5.1 Module wiring

`Core\Support\ModuleServiceProvider::bootModule()` auto-wires a module from its folder conventions, so adding
a module never requires editing global config except one line in `bootstrap/providers.php`:

| Folder / file                  | Wired to                                                     |
| ------------------------------ | ------------------------------------------------------------ |
| `Database/Migrations/central/` | `php artisan migrate`                                        |
| `Database/Migrations/tenant/`  | appended to `tenancy.migration_parameters.--path` → `tenants:migrate` |
| `routes/central.php`           | prefix `api/v1/central`, middleware `api` + `central`, names `central.*` |
| `routes/tenant.php`            | prefix `api/v1`, middleware `api` + `tenant`, names `tenant.*` |

A typical provider (`register()` binds services as singletons; `boot()` calls `bootModule()`, sets the
morph map, registers policies, permissions, settings and event listeners). See
[OrganizationServiceProvider.php](../app/Modules/Organization/OrganizationServiceProvider.php) and
[TimetableServiceProvider.php](../app/Modules/Timetable/TimetableServiceProvider.php).

Current provider order (`bootstrap/providers.php`): Tenancy, Horizon, Core, Central, Identity, Organization,
Timetable, Scheduling, Attendance, Leave, Overtime, Reports, Payroll. Keep new modules after the modules they depend on.

### 5.2 How modules talk to each other

**Rule: never query another module's tables directly.** Use its service classes or events.

| Mechanism                        | Owner        | Used for                                                                 |
| -------------------------------- | ------------ | ------------------------------------------------------------------------ |
| `PermissionCatalog::register()`  | Core         | A module declares its permissions and default roles                      |
| `SettingsRegistry::register()`   | Core         | A module declares typed tenant settings with defaults and validation     |
| `TenantDefaultsSyncRequested`    | Core event   | Idempotent per-tenant defaults (roles/permissions, settings, General department). Fired at provisioning and by `tenants:seed` |
| `TenantDatabaseProvisioned`      | Core event   | One-time setup of a new tenant (apply timezone, create first Tenant Admin) |
| `TenantPlanLimits` contract      | Core → Central | Read plan limits (e.g. `max_employees`) from inside tenant context     |
| `UserDeleted`                    | Identity event | Organization unlinks the employee and removes manager rows             |
| `AssignmentService::on($emp, $date)` | Organization | Department + location of an employee on a date                     |
| `MembershipService::on($emp, $date)` | Organization | Groups on a date, in precedence order (lowest `priority` first)    |
| `EmployeeVisibility::apply($query, $user)` / `canSee()` | Organization | The **only** rule for which employees a user may see. Attendance, leave and reports must scope through it |
| `ShiftCalendar::expand()`        | Timetable    | Lay a shift cycle onto real dates (UTC, DST-aware). Nobody else does cycle arithmetic |
| `TimetableRules::for($timetable)`| Timetable    | Effective grace minutes and punch windows (timetable override or workspace default) |
| `UsageRegistry::whenShiftInUse()` / `whenTimetableInUse()` | Timetable | Other modules (Scheduling) register "is this in use?" checkers so time rules lock once used |
| `AssignmentService::forEmployees()` / `MembershipService::forEmployees()` / `DepartmentTree::ancestorChains()` | Organization | Range lookups for many employees at once (no N+1) |
| `ScheduleResolver::forEmployee()` / `forEmployees()` | Scheduling | The **only** answer to "what is E scheduled to do on D, and why" (status, periods in UTC, timezone, deciding level) |
| `ScheduleChanged`                | Scheduling event | Fired after commit for every assignment, temporary-schedule or holiday change (scope + date range); attendance recalculation listens |
| `AttendanceCalculator::recalculate($ids, $from, $to)` | Attendance | Rebuilds attendance days (queued via `RecalculateAttendance`; one writer per tenant). Leave feeds it; overtime reads it |
| `AttendanceRecalculated` | Attendance event | Fired after each attendance write with the employee and changed dates; Overtime listens synchronously (same writer job) |
| `AttendanceQuery` | Attendance | Visibility-scoped reads of attendance days and per-status counts; `withPeriods()` for Overtime; `aggregateColumns()` / `aggregate()` / `aggregatePerEmployee()` / `dailyStats()` / `daysFor()` for Reports (one definition of every count) |
| `RefreshDailyStats` (listener) / `DailyStatsBuilder` | Reports | Keeps `attendance_daily_stats` in step inside the attendance writer job (on `AttendanceRecalculated`); rebuilds whole dates |
| `LeaveQuery::takenPerEmployee()` / `OvertimeQuery::summary()` / `totals()` | Leave / Overtime | Report reads of approved leave (half days) and overtime totals, scoped by visibility |
| `OvertimeCalculator` / `OvertimeQuery` | Overtime | Overtime records from attendance days (idempotent); per-employee approved/weighted/pending totals (for Reports) |
| `LeaveCalendar` (contract) / `LeaveChanged` (event) | Core ← Leave | Approved leave per employee and day, and its changes; Attendance consumes both without depending on Leave. Core binds an empty default |
| `PeriodLock` (contract) / `PayrollPeriodUnlocked` (event) | Core ← Payroll | Locked payroll days: `assertOpen()` before changing a work date, `lockedDates()` to skip them when calculating; unlocking announces the days for recalculation. Core binds a no-op default |
| `AttendanceDaysChanged` (event) | Attendance | Once per recalculation run with every changed date (Reports rollup, live Today board) |
| `LeaveBalanceService` / `LeaveLedger` | Leave | Balances are read from (and written to) the append-only ledger only |

### 5.3 Layering inside a module

```
Route → FormRequest (authorize + validate) → Controller (thin) → Service (rules, DB transaction)
      → Model(s) → API Resource → JSON
```

- **Controllers** never contain business logic; they call a service and wrap the result in a Resource.
- **Services** hold the rules, open short `DB::transaction()` blocks for multi-step writes, and throw
  `BusinessRuleException` (or `ValidationException`) when a rule is broken.
- **Enums** for every status/type; models cast them.
- **Configurable thresholds** (grace, rounding, debounce, OT rules) come from tenant settings, never constants.

---

## 6. Multi-tenancy: how a request finds its tenant

### 6.1 Hosts

| Host                          | Serves                                         |
| ----------------------------- | ---------------------------------------------- |
| `localhost` (any `CENTRAL_DOMAINS` host) | Super admin console + `/api/v1/central/*` |
| `<subdomain>.localhost`       | Tenant workspace + `/api/v1/*`                 |

Central routes return 404 on tenant hosts and tenant routes return 404 on central hosts. An unknown
subdomain returns `404 {"message":"Workspace not found."}` (never echoes the domain).

### 6.2 The tenant middleware group (`bootstrap/app.php`)

```
InitializeTenancyBySubdomain   resolve tenant from the subdomain, switch DB/cache/filesystem/queue context
PreventAccessFromCentralDomains
EnsureTenantIsActive           suspended tenant → 403
ScopeSessionToTenant           invalidate a session that belongs to another tenant
```

### 6.3 Storage per tenant

- Central DB: `database/central.sqlite` — `plans`, `tenants` (UUID id = file name), `domains` (subdomain
  label only), `super_admins`, central `audit_logs`, queue tables.
- Tenant DB: `database/tenants/<uuid>.sqlite` — everything else. Files are created, migrated and deleted
  **only through the tenancy package** (`Core\Tenancy\TenantSqliteDatabaseManager`), never by hand.
- Tenant filesystem paths are suffixed per tenant (`suffix_storage_path`). Backups resolve their absolute
  path *before* entering tenant context for that reason.

### 6.4 Provisioning (`Central\Services\TenantProvisioner`)

Synchronous and rollback-safe (about 1–3 s):

1. Short central transaction: create `tenants` + `domains` rows.
2. Outside the transaction: create the SQLite file, run tenant migrations, fire
   `TenantDatabaseProvisioned` and `TenantDefaultsSyncRequested` (roles, settings, defaults, first admin).
3. Any failure deletes the tenant rows and its file.

Tenant lifecycle: `provisioning` → `active` ⇄ `suspended`. Delete only when suspended, with the subdomain
typed as confirmation; a `VACUUM INTO` backup is written first. Subdomains are 2–63 chars
(`[a-z0-9-]`, no edge hyphens), immutable, and must not be in `config('ams.reserved_subdomains')`.

### 6.5 Isolation guarantees you must preserve

- Every tenant-scoped query runs inside tenant context. Jobs carry only IDs and run in tenant context via
  stancl's queue bootstrapper. Cache keys are tenant-tagged; the settings cache and spatie permission cache
  additionally include the tenant id (`ScopePermissionCacheToTenant`).
- Session cookies are host-only (`SESSION_DOMAIN=null`) and bound to their tenant, so a cookie replayed on
  another subdomain is rejected.
- Never put tenant IDs or secrets in client-facing errors or logs. Production must run `APP_DEBUG=false`.
- **Every module ships a tenant isolation test** that inspects the raw SQLite files.

---

## 7. Database conventions (SQLite)

- **SQLite is the only supported engine.** No MySQL/PostgreSQL-specific SQL (`JSON_TABLE`, `FILTER`,
  `DATE_FORMAT`, native enums/arrays, stored procedures). Use Eloquent / the query builder / portable SQL.
- **Pragmas on every connection** (`config/database.php`, verified by `SqlitePragmasTest`):
  `journal_mode=WAL`, `foreign_keys=ON`, `busy_timeout=5000`, `synchronous=NORMAL`,
  `transaction_mode=IMMEDIATE`.
- **One writer per file.** Keep write transactions short; never hold one across HTTP calls or slow work.
  Heavy writes (punch processing, recalculation, imports) go to queued jobs; punches for one tenant are
  processed sequentially.
- **Types:** explicit Laravel column types, model casts for enums/dates/booleans/JSON. Money and durations
  are **integers** (minor units, minutes, seconds) — never floats. Timetable times are integer
  *minutes from local midnight* (09:30 = 570).
- **Dates:** timestamps stored in UTC as `Y-m-d H:i:s`; work dates as `Y-m-d` via the
  `Core\Casts\LocalDate` cast (Laravel's `date` cast would store `Y-m-d 00:00:00`).
- **Case-insensitive uniqueness** via `->collation('nocase')->unique()` (codes, emails).
- **Constraints in the schema**, not only PHP: unique indexes (idempotency keys, `employee_id + work_date`),
  foreign keys (`restrict` where history matters), check constraints where useful.
- **Migrations:** SQLite has limited `ALTER TABLE` — keep migrations additive and simple; never rely on
  foreign-key changes after table creation; each migration must run on a fresh and an existing DB.
  Tenant migrations are named `YYYY_MM_DD_00MMNN_*` with a per-module number block
  (Core `0000xx`, Identity `0001xx`, Organization `0002xx`, Timetable `0003xx`).
- **Indexes:** review every migration for the queries it serves (attendance: `employee_id + work_date`,
  status, department).
- **Soft deletes** where history matters (users, employees, leave, attendance).
- **Backups:** `php artisan db:backup` uses `VACUUM INTO`. Never copy a live SQLite file.
- **Never commit** `*.sqlite`, `*.sqlite-wal`, `*.sqlite-shm`, or `database/tenants/`.

---

## 8. Time and dates

- All instants stored in **UTC**. Display and day boundaries use the tenant timezone
  (`general.timezone` setting); a **location** may override it.
- "Today" is always the tenant's calendar day: `Core\Support\TenantClock`.
- Never compare dates as strings in PHP; use Carbon (`CarbonImmutable`) with explicit timezones.
- A **work date** comes from the resolved shift, not the punch's calendar date. Overnight periods belong to
  the day they **start** (Mon 22:00–Tue 06:00 → Monday).
- DST is handled in `ShiftCalendar`: wall-clock times are converted per date, so a 22:00–06:00 night is 7 h
  on spring-forward and 9 h on fall-back. A start inside the skipped hour moves forward; an ambiguous time
  resolves to its first occurrence. `ScheduledPeriod::minutes()` returns real elapsed minutes.
- Overnight shifts, midnight punches and DST transitions **must have tests**.

---

## 9. Authentication, roles and permissions

### 9.1 Guards

| Who          | Where                        | Mechanism                                          |
| ------------ | ---------------------------- | -------------------------------------------------- |
| Super admin  | central host, `central` guard | Sanctum SPA cookie session, `super_admins` table  |
| Tenant users | tenant host                  | Sanctum SPA cookie session, tenant `users` table   |
| Devices/integrations | tenant host, ingestion routes only | Punch source key `Bearer ams_<prefix>_<secret>` (sha256 at rest, shown once), optional HMAC + IP allowlist |

Login is rate limited (5/min per host + email + IP); the API is limited to 120/min per host + user
(`config/ams.php`, `LOGIN_RATE_LIMIT`, `API_RATE_LIMIT`).

### 9.2 Roles

Super Admin (central) · **Tenant Admin** · **HR Manager** · **Department Manager** · **Employee**
(`Core\Enums\RoleName`). A tenant user has exactly one role.

User rules: only a Tenant Admin can grant/revoke/edit the Tenant Admin role or an admin account; nobody can
change their own role or status or delete themselves; a tenant always keeps at least one active Tenant Admin
(`409 last_tenant_admin`).

### 9.3 Permission catalog

Modules declare permissions in their service provider:

```php
$catalog->register('shifts.manage', [RoleName::HrManager]);   // Tenant Admin always gets everything
```

`tenants:seed --force` (or provisioning) syncs the catalog into every tenant's spatie tables. Current matrix:

| Permission                                                   | Admin | HR | Dept Mgr | Employee |
| ------------------------------------------------------------ | :---: | :-: | :-----: | :------: |
| users.view / create / update                                 | ✓ | ✓ |   |   |
| users.delete                                                 | ✓ |   |   |   |
| settings.view                                                | ✓ | ✓ |   |   |
| settings.update                                              | ✓ |   |   |   |
| audit-logs.view                                              | ✓ | ✓ |   |   |
| organization.view, locations.view, departments.view, groups.view | ✓ | ✓ | ✓ |   |
| organization.update                                          | ✓ |   |   |   |
| locations.manage, departments.manage, groups.manage          | ✓ | ✓ |   |   |
| employees.view (scoped by `EmployeeVisibility`)              | ✓ | ✓ | ✓ |   |
| employees.view-all, create, update, terminate, import        | ✓ | ✓ |   |   |
| employees.delete                                             | ✓ |   |   |   |
| timetables.view, shifts.view *(Module 3)*                    | ✓ | ✓ | ✓ |   |
| timetables.manage, shifts.manage *(Module 3)*                | ✓ | ✓ |   |   |

Everyone can read their own employee record (`GET /me/employee`).

### 9.4 Where authorization happens

- **FormRequest `authorize()`** — `return $this->user()?->can('timetables.manage') ?? false;`
- **Controller `$this->authorize('perm')`** for actions without a FormRequest (show, destroy, duplicate).
- **Policies** for record-level rules (`EmployeePolicy`, `UserPolicy`, `TenantPolicy`, `PlanPolicy`).
- **Data scoping** — managers only see their departments (incl. sub-departments) and groups, via
  `EmployeeVisibility`. Client-supplied filters can never widen it.
- **Frontend** `useCan()` hides actions and guards routes, but the backend is always the enforcer.

---

## 10. Backend request conventions

### 10.1 Lists (`Core\Http\Requests\IndexRequest`)

Every paginated list accepts `?page=2&per_page=25&sort=-created_at&search=jane&<filters>`. A leading `-`
means descending. Subclasses declare `sortable()` (public name → column), `defaultSort()` and
`filterRules()`. `per_page` defaults to 15, max 100 (`config/ams.php`).

### 10.2 Responses

- Always an **API Resource** (`data`, plus `links`/`meta` for paginated lists). Extra info goes in `meta`
  via `->additional()` — e.g. timetable detail returns `meta.locked` and `meta.effective`.
- `201` on create, `204` on delete.

### 10.3 Errors (JSON for every `api/*` request)

| Status | Shape                                              | When                                         |
| ------ | -------------------------------------------------- | -------------------------------------------- |
| 401 / 403 | `{message}`                                     | unauthenticated / forbidden / suspended tenant |
| 404    | `{message}`                                        | missing record, unknown workspace            |
| 409 / 422 | `{message, rule}` — `BusinessRuleException`     | valid request that breaks a business rule (`plan_in_use`, `last_tenant_admin`, `timetable_in_use`, `shift_locked`, …). Message is user-safe |
| 422    | `{message, errors: {field: [..]}}`                 | validation (incl. service-level `ValidationException::withMessages`) |
| 419    | CSRF token mismatch                                | the SPA client retries once after refreshing the cookie |
| 429    | rate limited                                       |                                              |

The frontend `ApiError` exposes `status`, field `errors` and business `rule`.

---

## 11. Cross-cutting services: settings, audit log, backups

### Settings (`Core\Settings`, `Core\Services\SettingsService`)

Typed key/value settings per tenant (`settings` table, JSON-encoded scalar). A module registers a
`SettingDefinition` (key, `SettingType`, default, label, description, validation rules). Defaults are copied
at provisioning; new definitions reach existing tenants with `tenants:seed` without overwriting values.
The Settings page groups them by key prefix (`general.*`, `attendance.*`).

| Key                                   | Default | Owner     |
| ------------------------------------- | ------- | --------- |
| `general.timezone`                    | `UTC` (overwritten by the timezone chosen at provisioning) | Core |
| `general.date_format`                 | `Y-m-d` | Core      |
| `general.time_format`                 | `H:i`   | Core      |
| `general.week_start`                  | `1` (Monday) | Core |
| `attendance.late_grace_minutes`       | 5       | Timetable |
| `attendance.early_leave_grace_minutes`| 5       | Timetable |
| `attendance.check_in_before_minutes`  | 120     | Timetable |
| `attendance.check_out_after_minutes`  | 240     | Timetable |

Read through `SettingsService::get()` (tenant-aware cache). Never hard-code a threshold.

### Audit log (`Core\Concerns\Auditable`, `Core\Services\AuditLogger`)

Add the `Auditable` trait to a model and create/update/delete/restore are recorded on the same connection
(central or tenant) with actor (user / super admin / system), IP, user agent and before/after values.
`$auditIgnore` skips noise; `$auditRedact` stores `[redacted]` (passwords always). Entries are append-only.
Tenant audit log is visible at `GET /audit-logs` and in the UI; the central audit log has no UI yet.

### Backups

`php artisan db:backup [--tenant=ID]` → `storage/backups/<timestamp>/` using `VACUUM INTO`. Tenant deletion
writes a backup first.

---

## 12. Feature catalog by module

Full request/response shapes: [docs/api/openapi.yaml](api/openapi.yaml). Run
`php artisan route:list --path=api --except-vendor` for the live list.

### 12.1 Module 1 — Foundation (`Core`, `Central`, `Identity`)

**Central API** (`/api/v1/central`, super admin):
`auth/login|logout|me` · `plans` (CRUD; a plan in use cannot be deleted → deactivate) ·
`tenants` (CRUD, `{id}/suspend`, `{id}/activate`; delete requires suspension + typed subdomain).

**Tenant API** (`/api/v1`): `auth/login|logout|me` · `users` (CRUD with role rules; delete = soft delete +
token revoke) · `settings` (GET/PUT) · `audit-logs` · `health`.

**Screens:** super admin console (Tenants, Plans); tenant workspace shell (Home, Users, Settings, Audit log).

### 12.2 Module 2 — Organization & Employees (`Organization`)

Key decisions: department/location/group membership is **effective-dated** (`effective_from`/`effective_to`,
inclusive, null = open-ended); departments nest; group `priority` is a unique integer (lowest wins);
`employee_code` is the punch identifier for now; locations may override the timezone; employee ↔ login is
optional 1:1; plan `max_employees` counts active + inactive employees; minimal PII (no DOB/national ID/gender).

| Table | Purpose |
| --- | --- |
| `organization_profile` | single row |
| `locations` | code, optional timezone |
| `departments`, `department_managers` | self-nesting tree + managers |
| `groups`, `group_managers` | cross-department groups with unique priority |
| `employees` | code (NOCASE unique incl. soft-deleted), status, hire/termination dates, optional `user_id` |
| `employee_assignments` | dated department + location history |
| `group_memberships` | dated group history |
| `employee_imports` | queued CSV import status and row errors |

**Business rules:** history periods never overlap; a transfer on D ends the covering period on D−1 (backdated
transfers slot in without rewriting later history); termination closes history and (by default) deactivates
the login; rehire starts a new period; plan limit checked inside the write transaction; anything referenced
by history cannot be deleted (deactivate instead); department cycles refused.

**Endpoints:** `organization` · `locations` · `departments` (+ `{id}/managers`) · `groups` (+ `{id}/managers`) ·
`employees` (+ `history`, `transfer`, `terminate`, `reinstate`, `groups`, `groups/{group}/end`, `user`) ·
`employees/import`, `employee-imports` · `me/employee`.

**CSV import:** required `employee_code, first_name, last_name, hire_date, department_code`; optional
`email, phone, job_title, employment_type, location_code, group_codes` (`;`-separated). Max 2 MB / 5,000
rows. Each row in its own short transaction; errors reported by line.

**Screens:** Employees list (filters, search, import wizard), Employee profile (Details / History / Groups),
Departments tree, Groups, Locations, Organization, My profile (mobile-friendly).

### 12.3 Module 3 — Timetable & Shifts (`Timetable`) — *approved 2026-10-21*

Approved decisions (2026-10-21): weekly **and** N-day rotation cycles (anchor date set when scheduled in
Module 4); split shifts up to **3 timetables per cycle day**, no overlaps (including overnight spill into the
next day and wrap-around from the last cycle day to the first); fixed and floating breaks, paid or unpaid;
grace and punch windows as workspace defaults with nullable per-timetable override; time rules become
**read-only once a schedule uses them** (duplicate and reschedule instead); overnight work date = start day;
flexible timetables have optional core hours; holidays belong to Module 4.

| Table              | Notes |
| ------------------ | ----- |
| `timetables`       | `code` NOCASE unique, `type` (fixed/flexible), `color`, `start_minute`, `duration_minutes` (may cross midnight), flexible: `required_minutes`, optional core hours (`core_start_offset` + `core_duration_minutes`); nullable overrides: `late_grace_minutes`, `early_leave_grace_minutes`, `check_in_before_minutes`, `check_out_after_minutes`, `min_work_minutes`; `is_active` |
| `timetable_breaks` | `kind` (fixed/floating), `start_offset` (fixed only, minutes after start), `duration_minutes`, `is_paid`, `position`, optional `name` (a label; may change while locked); max 4 per timetable |
| `shifts`           | `code`, `cycle_unit` (day: up to 56 days / week: up to 8 weeks), `cycle_length`, `description`, `is_active` |
| `shift_days`       | (`shift_id`, `day_index`, `timetable_id`) unique; week cycles: index 0 = Monday of week 1; timetable FK `restrict` |

**Validation highlights:** flexible timetables need `required_minutes ≤ window`, core hours inside the window,
floating breaks only; fixed breaks must sit inside the timetable and not overlap; breaks (plus required time)
must fit; a day outside the cycle or an inactive timetable is refused.

**Locking:** `UsageRegistry` asks registered checkers whether a shift/timetable is in use. In use →
cycle changes return `409 shift_locked`, time-rule changes on a timetable are refused, deletes return
`409 shift_in_use` / `timetable_in_use`. Scheduling registers the checker: a shift is in use once a schedule
using it has started.

**Endpoints:** `timetables` (CRUD, `{id}/duplicate`) · `shifts` (CRUD, `{id}/duplicate`, `PUT {id}/days`,
`GET {id}/preview?from&to[&anchor][&timezone]` — max 62 days, returns UTC periods per date with cycle index).

**Services for later modules:** `ShiftCalendar::expand($shift, $anchor, $from, $to, $tz)` →
`[Y-m-d => ScheduledPeriod[]]`; `ShiftCalendar::cycleIndex()`; `TimetableRules::for()`; `UsageRegistry`.

**Screens:** Timetables (list + form dialog with breaks and a timeline bar), Shifts (list), Shift detail
(cycle editor + calendar preview). Settings page gains an "Attendance defaults" group. Demo data:
`DemoTimetableSeeder` runs for the `acme` tenant.

Full detail: [modules/timetable.md](modules/timetable.md).

### 12.4 Module 4 — Scheduling (`Scheduling`) — *approved 2026-10-21*

Approved decisions (2026-10-21): temporary schedule = a shift (with anchor) or days off for a date range;
sub-departments inherit the nearest ancestor's schedule; anchor defaults to the start date; shifts lock once a
schedule using them has **started**; backdated changes split history and fire `ScheduleChanged`; holidays are
workspace-wide or per location, optionally yearly; read-only roster (≤ 31 days); temporary beats holidays.

**Precedence** (`ScheduleResolver`): temporary > employee > group (lowest priority among the employee's groups
*on that date* that have a schedule) > department (on that date, else nearest ancestor) > organization default.
Department, groups and location are read per date, so transfers switch schedule and timezone on the right day.
Statuses: `working`, `rest`, `holiday` (periods kept for OT), `day_off`, `unscheduled`, `not_employed`.

| Table                  | Notes |
| ---------------------- | ----- |
| `schedule_assignments` | `target_type` + `target_id` (null for organization; no FK), `shift_id` FK restrict, `anchor_date`, `effective_from`/`effective_to`; unique (target, from); no overlaps per target |
| `temporary_schedules`  | `employee_id`, `date_from`/`date_to` (≤ 366 days), `shift_id` (null = day off), `anchor_date`, `reason`; no overlaps per employee |
| `holidays`             | `date`, `name`, `location_id` (null = everyone), `recurs_yearly`; one per date + scope |

**Endpoints:** `schedule-assignments` (CRUD) · `temporary-schedules` (list) · `employees/{id}/temporary-schedules`
· `temporary-schedules/{id}` · `employees/{id}/schedule` · `me/schedule` · `roster` · `holidays` (CRUD).

**Screens:** Schedules (Organization / Departments / Groups / Employees / Temporary tabs), Roster, Holidays,
employee profile Schedule tab, My schedule. Demo data: `DemoSchedulingSeeder` (4 assignments, 7 holidays).

Full detail: [modules/scheduling.md](modules/scheduling.md).

### 12.5 Module 5 — Punch Ingestion & Attendance Engine (`Attendance`) — *approved 2026-10-21*

**Flow:** `POST /punches` (punch source key; HMAC and IP allowlist optional) stores the raw punch and answers
`202` → `ProcessPunches` (one writer per tenant) matches it to an employee (`punch_matches`) or keeps it as
an orphan → `AttendanceCalculator` (= `ScheduleResolver` + `DayEvaluator`) writes `attendance_days` with
periods and punch roles. Raw punches are append-only (SQLite triggers); idempotency is two unique indexes.

**Rules:** punch windows per period, nearest-edge placement (overnight, split shifts), debounce, grace from
the scheduled edges, unpaid breaks, rounding, flexible core hours and required time, minimum work → absent,
`in_progress` until the last window closes, holiday/rest-day work recorded. Recalculated on new punches,
`ScheduleChanged`, approved corrections, linked orphans, on demand, and every 15 minutes
(`attendance:close-days`); unchanged days are not rewritten (fingerprint).

**Corrections** (add / ignore a punch) need approval (HR; managers for their people; never your own);
HR entries apply at once. **Unmatched punches** are linked (the identifier becomes the employee's punch
identifier) or dismissed.

**Screens:** Today (live tiles, 30 s), Attendance, day drawer, Corrections, Punch log, Unmatched punches,
Punch sources (key shown once), employee Attendance tab, My attendance; the sidebar is grouped by area.

Full detail: [modules/attendance.md](modules/attendance.md).

### 12.6 Module 6 — Leave Management (`Leave`) — *approved 2026-10-27*

**Balances are a ledger:** `leave_ledger` is append-only (triggers); a balance is the sum of a leave year's
entries (grant, accrual, carry_forward, expiry, request, cancellation, recount, adjustment), stored in whole
half-days. `leave:accrue` (hourly, idempotent via a unique period key) writes grants (prorated for joiners),
monthly accruals and the year end (carry up to the cap, the rest expires; negative balances carry in full).

**Requests** count scheduled working days only (`ScheduleResolver`): holidays, rest days and days off are free;
half days at either end; no overlaps except opposite halves; balance down to the type's negative limit; single-step
approval (managers for their people or HR, never one's own; HR entries approved at once); cancellation credits back.
Schedule or holiday changes recount active requests (`recount` entries).

**Attendance:** Leave implements Core's `LeaveCalendar` and fires Core's `LeaveChanged`; full days are `on_leave`,
half days excuse that half and evaluate the other from the day's midpoint.

**Screens:** My leave, Leave requests, Leave calendar, Leave types, employee Leave tab; "On leave" in attendance.

Full detail: [modules/leave.md](modules/leave.md).

### 12.7 Module 7 — Overtime Management (`Overtime`) — *approved 2026-10-28*

**Calculation** (from attendance days, rules in `overtime.*` settings): on working days, time after the scheduled
end, capped by worked − scheduled (a late start is made up first); early arrival only when enabled; rest days, days
off and unscheduled days count all worked time at the rest-day rate, holidays at the holiday rate; none on full
leave days or days in progress. Minimum 30 minutes, rounded down to 15, optional daily cap; rates 150 / 200 / 200 %
give **weighted minutes** (no money). It runs inside the attendance writer job (listener on
`AttendanceRecalculated`) and is idempotent.

**Approval** after the fact by the employee's manager or HR, never one's own; fewer minutes may be approved; bulk
approval. A decided record whose calculation changes returns to pending (`changed_after_decision`). HR records
manual overtime (approved at once when entered for someone else).

**Screens:** Overtime inbox, Overtime summary, My overtime, employee Overtime tab, overtime in the day drawer,
Settings → Overtime.

Full detail: [modules/overtime.md](modules/overtime.md).

### 12.8 Module 8 — Reports & Analytics (`Reports`) — *approved 2026-11-10*

**Rollup** `attendance_daily_stats` (work date × department × location as recorded on the day; 0 = none), kept in step
by the attendance writer job and rebuilt with `reports:rebuild-stats`. HR and Tenant Admin read it; managers, group
filters and searches aggregate live for the viewer's people. Both use `AttendanceQuery::aggregateColumns()`.

**KPIs:** attended = present + late + early_leave + late_early + incomplete; working days = attended + absent;
attendance rate = attended ÷ working days; punctuality = attended days without late minutes ÷ attended.

**Dashboard** (cached 60 s per viewer scope) and five **reports** (`GET /reports/{report}`: attendance-summary, register,
exceptions, leave, overtime; ≤ 92 days). **Exports** (`POST /reports/exports`, CSV/XLSX via openspout, ≤ 366 days)
are generated in the queue, downloadable by the requester only, deleted after 7 days by `reports:prune-exports`.
Permissions `reports.view`, `reports.export` (HR, Department Manager).

**Screens:** Reports section — Dashboard, Attendance summary, Monthly register, Late and absent, Leave taken, Overtime
report, Exports; an Export menu on each report.

Full detail: [modules/reports.md](modules/reports.md).

### 12.9 Module 9 — Hardening (`Payroll` + cross-cutting) — *built, awaiting approval*

- **Security:** `SecurityHeaders` middleware (strict API CSP, frame deny, HSTS over HTTPS when configured), log masking
  on every channel (`Core\Logging\MaskSensitiveData`), `heavy` / `password` rate limiters, a route audit test.
- **Performance:** one `AttendanceDaysChanged` per run; scoped recalculation for department/group/location schedule
  changes; `EXPLAIN QUERY PLAN` tests; lazy report pages.
- **Payroll periods** (`payroll_periods`): HR locks finished days, a Tenant Admin unlocks with a reason. Locked days are
  frozen (not recalculated) and changes touching them answer `409 period_locked`.
- **Leave attachments** (`leave_request_attachments`): PDF/JPG/PNG ≤ 5 MB, ≤ 3 per request.
- **Password reset** (workspaces and console) and **invitations** (7-day link to set a first password).
- **Live Today board** over Reverb (`workspace.{subdomain}.attendance`), **central audit log** page, scheduled
  backups with retention, [deployment and restore guide](deployment.md).

Full detail: [modules/hardening.md](modules/hardening.md).

---

## 13. Planned modules and their domain rules

All nine modules are built. Every new feature still follows the workflow in §18 (plan, approval, one module or
change at a time). Mandatory edge-case tests in every area: overnight shifts, DST, duplicate punches, missing
punches, mid-period schedule changes, backdated corrections, leave overlapping holidays, negative balances.

---

## 14. Frontend architecture

### 14.1 Layout

```
frontend/src/
├── main.tsx, App.tsx       App chooses central vs tenant app by hostname (lib/host.ts)
├── apps/
│   ├── central-app.tsx     super admin console (lazy-loaded)
│   └── tenant-app.tsx      tenant workspace: nav items + guarded routes
├── components/
│   ├── ui/                 shadcn/ui primitives (do not hand-edit beyond tokens)
│   ├── app-shell.tsx       sidebar/topbar, mobile sheet nav, skip link
│   ├── data-table.tsx      server-side table (sort, paginate, states)
│   ├── states.tsx          loading skeleton, empty and error states
│   ├── form-field.tsx, confirm-dialog.tsx, search-input.tsx, status-badge.tsx, theme-toggle.tsx
├── features/<feature>/     one folder per feature: api.ts (types + query/mutation hooks), pages, dialogs, schemas.ts
├── hooks/                  use-list-params.ts, use-debounced-value.ts
└── lib/
    ├── api/client.ts       fetch client: tenantApi (/api/v1), centralApi (/api/v1/central)
    ├── api/types.ts        Paginated<T>, Resource<T>, ListParams
    ├── forms.ts            map server 422s onto form fields, errorMessage()
    ├── format.ts, host.ts, query-client.ts, utils.ts
```

### 14.2 Key patterns

- **Two apps, one build.** `resolveAppContext()` reads the hostname: `localhost` → central console,
  `<sub>.localhost` → tenant workspace. Each app is lazy-loaded so tenants never download the console.
- **Same-origin API.** Vite proxies `/api` and `/sanctum` to Laravel **without rewriting the Host header**,
  so Laravel sees `acme.localhost` and cookies stay first-party.
- **API client** (`lib/api/client.ts`): Sanctum CSRF handshake, retries a 419 once, throws typed `ApiError`
  (status, field errors, business `rule`).
- **Feature `api.ts`**: TypeScript interfaces that mirror the PHP Resource (comment says which) and TanStack
  Query hooks (`useXxx`, `useCreateXxx`, …) that invalidate related queries on success.
- **Lists** use `DataTable` + `useListParams({ defaultSort, filters })`; page/sort/search/filters live in
  the URL (shareable, survive reload) and map 1:1 onto the backend `IndexRequest` contract.
- **Forms**: React Hook Form + Zod schema mirroring backend rules; server 422s mapped back onto fields.
- **Permissions**: `useCan()(perm)` hides nav items and actions; `guarded(perm, routes)` in
  `tenant-app.tsx` guards routes.
- **States**: every page has skeleton, empty and error states.
- **Accessibility/theming**: light/dark/system, responsive (employee views must work on mobile), labelled
  controls, visible focus, WCAG AA. Use Tailwind design tokens and consistent status colors
  (present, late, absent, leave, holiday).
- No `any` without a justifying comment. No new UI library without approval.

### 14.3 Tenant navigation (current)

Home · My profile (`/me`) · Employees · Departments · Groups · Locations · Organization · Timetables · Shifts ·
Users · Settings · Audit log. Home and My profile are visible to everyone; the rest need their `*.view`
permission (Employees: `employees.view` or `employees.view-all`).

---

## 15. Local development

### 15.1 Standard setup

```bash
composer setup                       # install, .env, key, central.sqlite, tenants dir, migrate --seed
php artisan serve                    # API
cd frontend && npm install && npm run dev
```

- Super admin console: `http://localhost:<spa-port>` — `admin@ams.test` / `password`
- Demo tenant: `http://acme.localhost:<spa-port>` — `admin@acme.test` / `password1`
  (local seed accounts from `.env` `SEED_*`; `*.localhost` resolves to 127.0.0.1 in modern browsers)

Queues: `php artisan horizon` on Linux; on Windows (no `pcntl`) use `php artisan queue:work`.
Without Redis (local only): `CACHE_STORE=array`, `CACHE_LIMITER=file`, `QUEUE_CONNECTION=database`.

Using non-default ports: set `VITE_API_PROXY_TARGET` in `frontend/.env.local`, run Vite with `--port`, and add
the origin to `SANCTUM_STATEFUL_DOMAINS`.

### 15.2 Notes for the current Windows workstation

- Ports 8000 and 5173 are taken by other software, so this project runs the **API on :8010** and the
  **SPA on :5180** (`frontend/.env.local`, `SANCTUM_STATEFUL_DOMAINS`). `.claude/launch.json` defines both
  (`api`, `web`).
- The C: drive is full: set `TMP`/`TEMP` (and npm cache) to `storage/tmp` on D:, or SQLite fails with
  "unable to open database file". `php artisan serve` drops `TMP`, so the API runs as
  `php -S 127.0.0.1:8010 -t public public/index.php`.
- PHP (Herd) is on PATH only in PowerShell; in Git Bash prefix
  `export PATH="/c/Users/Techive/.config/herd/bin/php84:$PATH"`.
- Redis is not installed; use the no-Redis settings above.

### 15.3 Commands

| Command | Purpose |
| --- | --- |
| `php artisan migrate --seed` | central schema + plans (+ super admin and demo tenant `acme` in local) |
| `php artisan tenants:migrate` | run tenant migrations on every tenant DB |
| `php artisan tenants:seed --force` | re-sync roles/permissions, missing settings and defaults (idempotent) |
| `php artisan db:backup [--tenant=ID] [--prune]` | `VACUUM INTO` backups; `--prune` deletes folders older than `BACKUP_KEEP_DAYS` (scheduled daily 02:30) |
| `php artisan route:list --path=api --except-vendor` | live endpoint list |
| `php artisan punches:simulate --tenant=acme` | punches from real schedules (`--http --key=…` posts through the API; `--overtime-rate` sets who stays late) |
| `php artisan attendance:close-days` | re-evaluate recent days (scheduled every 15 minutes) |
| `php artisan leave:accrue` | leave grants, accruals, carry-forward, expiry (scheduled hourly, idempotent) |
| `php artisan reports:rebuild-stats [--tenant= --from= --to=]` | queue a rebuild of the report rollup (once on workspaces with earlier attendance) |
| `php artisan reports:prune-exports` | delete expired report export files (scheduled daily) |
| `php artisan schedule:work` | run the scheduler locally |
| `composer test` | Pest |
| `./vendor/bin/pint` / `--test` | format / check PHP style |
| `npm run lint` · `npm run typecheck` · `npm run build` · `npm run format` | frontend gates (in `frontend/`) |

---

## 16. Testing

- **Location:** `app/Modules/<Module>/Tests/{Unit,Feature}` (registered in `tests/Pest.php`). Files opt in to
  `RefreshDatabase` themselves (backup tests must run outside a transaction).
- **Databases in tests:** central DB in memory; tenant DBs are real files in
  `storage/framework/testing/tenants-<pid>`, removed after each test.
- **Required per module:** unit tests for business rules, a feature test for **every endpoint**, and a
  **tenant isolation test** proving tenant A's raw SQLite file contains none of tenant B's data.
- Use factories, never hand-built rows. Freeze time; tests must not depend on order or the real clock.
- Verify N+1 safety with query counts where it matters (e.g. the employee list stays flat from 2 to 22 rows).
- **Never weaken or delete a failing test** to make it pass — fix the cause or raise it.
- Frontend has no test runner (not in the Definition of Done); gates are `tsc`, ESLint, build, and manual
  browser checks.

Test counts at approval: Module 1 — 126 tests; Module 2 — 189 tests / 670 assertions total.

---

## 17. How-to recipes

### Add a new module

1. Create `app/Modules/<Name>/<Name>ServiceProvider.php` extending `ModuleServiceProvider`; call
   `$this->bootModule()` in `boot()`; bind services as singletons in `register()`.
2. Register the provider in `bootstrap/providers.php` (after its dependencies).
3. Add tenant migrations in `Database/Migrations/tenant/` with the next number block (`0004xx`, …).
4. Add `routes/tenant.php` wrapped in `Route::middleware(['auth:sanctum', 'throttle:api'])`.
5. Register the morph map (`Relation::enforceMorphMap`), permissions, settings and listeners in `boot()`.
6. Run `php artisan tenants:migrate` and `php artisan tenants:seed --force`.
7. Write unit, feature and isolation tests; add `docs/modules/<name>.md`, OpenAPI paths and a changelog entry.

### Add an endpoint

1. FormRequest with `authorize()` (permission) and `rules()`; extend `IndexRequest` for lists.
2. Service method with the logic (short transaction; `BusinessRuleException` with a stable `rule` slug).
3. Thin controller method returning a Resource.
4. Route in the module's `routes/tenant.php`.
5. Feature test (happy path, validation, forbidden, rule violations); update `openapi.yaml`.
6. Frontend: types + hook in `features/<x>/api.ts`, UI behind `useCan()`.

### Add a permission

`$catalog->register('thing.action', [RoleName::HrManager, ...])` in the module provider →
`php artisan tenants:seed --force` → enforce in FormRequest/controller/policy → `useCan('thing.action')` in UI →
update the matrix in the module doc and §9.3 here.

### Add a tenant setting

Register a `SettingDefinition` in the module provider (key prefixed by group, typed, default, rules) →
`tenants:seed --force` copies the default without overwriting existing values → read via
`SettingsService::get()` → add a group title in `frontend/src/features/settings/settings-page.tsx` if it is a
new prefix.

### Audit a model

Add `use Auditable;` to the model; list noisy columns in `$auditIgnore` and secrets in `$auditRedact`.

### Add a frontend page

Create `features/<x>/<x>-page.tsx`, use `useListParams` + `DataTable` for lists, add skeleton/empty/error
states, add a nav item and a `guarded(perm, [...])` route in `apps/tenant-app.tsx`.

### Change an existing table

Prefer a new additive migration (add column/table/index). SQLite cannot alter columns or foreign keys in
place reliably; ask before anything that rewrites a table. Never run `migrate:fresh` or drop data outside
local dev.

---

## 18. Workflow, quality gates and Git

### Module workflow

1. Work one module at a time, in the roadmap order.
2. **Plan first** (entities, endpoints, screens, edge cases, open questions); wait for approval if anything
   touches the data model.
3. Ask, don't guess, on ambiguity that affects schema, business rules or tenancy.
4. Keep changes scoped; do not refactor unrelated modules without asking.
5. No silent TODOs, commented-out code or placeholder logic; list unfinished items in the summary.
6. End with a summary (what was built, how to run, decisions, limitations, TODOs) and stop until approved.

### Definition of done (per module)

- Migrations, models, factories, seeders
- Services, policies, controllers, Form Requests, API Resources
- Pest: unit (rules), feature (every endpoint), tenant isolation
- React pages, components, hooks, API client, loading/empty/error states
- Docs: `docs/modules/<module>.md`, OpenAPI, changelog
- `composer test`, Pint, ESLint, `tsc --noEmit` all pass
- Summary delivered, awaiting approval

### Git

- Conventional Commits: `feat(scheduling): …`, `fix(attendance): …`, `test(leave): …`, `docs(…): …`.
- One logical change per commit; never commit failing code.
- No force-push, history rewrites, or touching other branches without asking.
- Work happens on `master`; `main` is the PR base.

### Never do

- Bypass tenancy scoping or policies "to make it work".
- Delete or overwrite raw punch data.
- Hard-code business thresholds.
- Run destructive DB commands outside local dev without asking.
- Install major dependencies without approval.
- Skip tests or linters.
- Commit `.sqlite`, WAL/SHM files or tenant databases, or secrets (`.env`).
- Add MySQL/PostgreSQL-only SQL or switch engines without asking.
- Copy a live SQLite file as a backup.

---

## 19. Known limitations and open follow-ups

- Horizon cannot run on Windows; use `queue:work`.
- No restore endpoint for soft-deleted users (email stays reserved).
- Plan `features` JSON stored but not enforced (`max_employees` is enforced by Organization).
- Transfers/terminations cannot be undone — correct with another dated change; history rows are not editable.
- Department Manager visibility is "as of today"; historical membership views belong to Reports.
- No bulk group add; CSV import is create-only.
- `ScheduleChanged` for a department/group/location recalculates everyone employed in the range (unchanged days are skipped).
- New employee codes are not checked against punch identifiers (the code wins for new punches).
- A pending punch correction cannot be withdrawn by its requester.
- Leave: half days only (no hourly leave), no automatic cancellation of future leave on termination, year-end carry is not redone for late changes in a closed leave year.
- Overtime: lock paid days with payroll periods (Module 9), one rule set per workspace, weighted minutes only (no money), no pre-approval, work on full leave days only via manual entries.
- Hardening follow-ups: no decision emails, setting a password does not end other browser sessions, no PDF exports or hourly leave.
- Reports: no PDF, scheduled or emailed reports; leave and overtime reports ignore location/group and use the current
  department; a large backfill refreshes each date once per recalculated employee; no payroll period locking yet.
- Temporary schedules are per employee only; moving holidays (Eid, etc.) are entered each year.
- Schedule rows whose department/group/employee was deleted show as "Deleted" and are ignored.
- OpenAPI spec is hand-maintained; keep it in sync with routes and resources.
- No frontend test runner.

---

## 20. Glossary

| Term | Meaning |
| --- | --- |
| Tenant / workspace | One customer company; one subdomain, one SQLite file |
| Central | The super admin side: plans, tenants, domains |
| Plan | Subscription tier with `max_employees` and `features` |
| Effective-dated | A row valid from `effective_from` to `effective_to` (inclusive, null = open) |
| Assignment | An employee's department + location for a date range |
| Group priority | Unique integer; lowest wins when an employee is in several groups |
| Timetable | One work period (fixed or flexible) with breaks, grace and punch windows |
| Shift | A repeating cycle (N days or N weeks) of timetables per cycle day |
| Anchor date | The date a shift cycle starts counting from (set when scheduled) |
| Cycle index | Which day of the cycle a date falls on |
| Scheduled period | A timetable placed on a work date with real UTC start/end |
| Work date | The date a period (and its punches) belongs to — the day it starts |
| Grace minutes | Tolerance before a check-in is late / a check-out is early |
| Punch window | How early a check-in / how late a check-out is still accepted |
| Raw punch | The append-only timestamp received from a device/API |
| Orphan punch | A punch whose identifier matches no employee; queued for review |
| Business rule (`rule`) | Stable slug in a 409/422 response, e.g. `shift_locked` |
