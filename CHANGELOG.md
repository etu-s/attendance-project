# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added — Module 5: Punch Ingestion & Attendance Engine

- Attendance module (`app/Modules/Attendance`): `POST /punches` and `/punches/batch` with per-source keys
  (hashed, shown once), optional HMAC signatures and IP allowlists, rate limiting, strict validation,
  idempotency in unique indexes, and an append-only raw punch table enforced by SQLite triggers.
- Queue processing with one attendance writer per tenant; unknown identifiers kept as unmatched punches
  that can be linked (teaching a new punch identifier) or dismissed.
- Attendance engine: punch windows, nearest-period placement (overnight, split shifts), debounce,
  grace, unpaid breaks, rounding, flexible core hours, minimum work, holiday/rest-day work, DST-accurate
  scheduled time; recalculation on schedule changes, corrections and every 15 minutes; unchanged days are
  not rewritten.
- Corrections (add a missed punch / ignore a wrong one) with approval rules and audit trail.
- `php artisan punches:simulate` (direct or over HTTP) and `php artisan attendance:close-days`.
- SPA: Today board, Attendance list, day drawer, Corrections, Punch log, Unmatched punches, Punch sources,
  employee Attendance tab, My attendance; grouped sidebar.
- 65 new Pest tests (352 total); OpenAPI spec v0.5.0.

### Fixed

- The local `database` queue wrote jobs dispatched inside a workspace to that workspace's SQLite file
  (no `jobs` table there); queued jobs now always use the central connection.

### Added — Module 4: Scheduling

- Scheduling module (`app/Modules/Scheduling`): dated shift assignments for the organization default,
  departments (inherited by sub-departments), groups (by priority) and employees; temporary overrides
  (shift or days off); holidays (workspace or location, optionally yearly).
- `ScheduleResolver`: the single source for "what is this employee scheduled to do on date D, and why",
  date-accurate across transfers, location timezones, hire and termination; constant query count.
- Backdated changes split history and fire `ScheduleChanged` (for Module 5 recalculation).
- Shifts and timetables become read-only once a schedule using them has started.
- Roster API and screen; Schedules (incl. a Temporary tab across employees), Holidays, employee Schedule tab
  and My schedule screens.
- 44 new Pest tests; OpenAPI spec v0.4.0.

### Added — Module 3: Timetable & Shifts

- Timetable module (`app/Modules/Timetable`): fixed and flexible timetables (minutes from midnight,
  overnight allowed, optional core hours), fixed/floating paid/unpaid breaks, per-timetable grace and punch
  windows over new `attendance.*` workspace defaults.
- Shifts: weekly (up to 8 weeks) and N-day (up to 56 days) cycles with up to 3 non-overlapping timetables
  per day, overlap checks across midnight and across the cycle wrap.
- `ShiftCalendar`: lays a cycle onto real dates in a timezone (UTC instants, DST-aware, work date = start
  date); preview endpoint.
- `UsageRegistry`: time rules become read-only once a schedule uses them (Module 4 registers usage);
  duplicate endpoints for timetables and shifts.
- SPA: Timetables list and editor with a live 24-hour timeline, Shifts list, cycle editor and 28-day preview,
  Attendance defaults in Settings.
- 52 new Pest tests; OpenAPI spec v0.3.0.

### Fixed

- Local demo seeding (`composer setup`) failed because `DemoTenantSeeder` did not import the Organization
  demo seeder.

### Added — Module 2: Organization & Employees

- Organization module (`app/Modules/Organization`): organization profile, locations (with timezone
  override), nested departments, cross-department groups with unique priority, department/group managers.
- Employees with effective-dated department/location history and group memberships; transfer (incl.
  backdated), terminate, rehire, hire-date correction, optional linked login.
- `EmployeeVisibility`: managers see employees of their departments (incl. sub-departments) and groups.
- Plan `max_employees` enforced on create, import and rehire.
- Queued CSV employee import with row-level error report.
- Core: `LocalDate` cast (`Y-m-d` storage), `TenantClock`, `TenantPlanLimits` contract; Identity:
  `UserDeleted` event.
- SPA: Employees list and profile, Departments tree, Groups, Locations, Organization, My profile, CSV import.
- 63 new Pest tests (189 total); OpenAPI spec v0.2.0.

### Fixed

- Form fields placed side by side no longer shift when a neighbor shows a hint or error.

### Added — Module 1: Foundation

- Laravel 13 API as a modular monolith (`app/Modules/{Core,Central,Identity}`) with a module loader for
  central/tenant migrations and routes.
- Multi-tenancy with stancl/tenancy v3: subdomain identification, one SQLite file per tenant
  (`database/tenants/<uuid>.sqlite`), WAL + foreign keys + busy timeout on every connection.
- Central API: super admin auth, plans CRUD, tenants CRUD with synchronous, rollback-safe provisioning,
  suspend/activate, and guarded deletion with a pre-delete backup.
- Tenant API: session auth (Sanctum SPA cookies), users CRUD with role rules (single role, admin-only
  admin management, last-admin and self-modification guards), typed settings, audit log, health.
- Roles and permissions via spatie/laravel-permission, driven by a module-extensible permission catalog.
- Append-only audit logging with password redaction, in the central DB and in each tenant DB.
- Security: sessions bound to their tenant, per-tenant permission cache, tenant-aware settings cache,
  login/API rate limiting.
- `php artisan db:backup` (VACUUM INTO) for central and tenant databases.
- React SPA (`frontend/`): super admin console (tenants, plans) and tenant workspace (home, users,
  settings, audit log) with server-side tables, light/dark mode and responsive layout.
- 126 Pest tests, including tenant isolation, SQLite pragma, provisioning rollback and session-replay tests.
- Docs: `docs/modules/foundation.md`, `docs/api/openapi.yaml`.
