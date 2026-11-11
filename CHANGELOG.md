# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added — Module 9: Hardening

- Security: headers on every API response (strict CSP, frame deny, nosniff, HSTS over HTTPS when configured);
  personal data and secrets masked in every log channel; `heavy` and `password` rate limiters; a route audit test
  (every API route authenticated, rate limited and in its host group); all `composer audit` advisories fixed with
  patch updates; production checklist in `.env.example`.
- Performance: one `AttendanceDaysChanged` per recalculation run (the reports rollup refreshes each date once);
  department, group and location schedule changes recalculate only the people they reach; index-usage tests; report
  pages and the WebSocket client load on demand (tenant bundle 687 kB → ~300 kB).
- Payroll module: lock payroll periods of finished days (HR), unlock with a reason (Tenant Admin). Locked days are
  frozen: no recalculation, and corrections, leave, overtime decisions and schedule changes touching them answer
  `409 period_locked`. Core `PeriodLock` contract and `PayrollPeriodUnlocked` event. Payroll periods page; reports
  note locked periods.
- Leave attachments: PDF/JPG/PNG up to 5 MB, 3 per request, visible to the employee, approvers and HR.
- Password reset for workspace users and super admins; email invitations to set a first password (7 days).
- Live Today board over Reverb (private per-workspace channel, polling fallback); central audit log API and page;
  `db:backup --prune` scheduled daily with retention; deployment and restore guide (`docs/deployment.md`).
- New dependencies: `laravel-echo` and `pusher-js` (Reverb client, loaded only when configured).
- 45 new Pest tests (476 total); OpenAPI spec v0.9.0.

### Added — Module 8: Reports & Analytics

- Reports module (`app/Modules/Reports`): `attendance_daily_stats`, a rollup of calculated attendance per work date,
  department and location. The attendance writer keeps it in sync through `AttendanceRecalculated`; it rebuilds
  whole dates and is idempotent. `php artisan reports:rebuild-stats` rebuilds it (run once on existing workspaces).
- Dashboard: attendance rate and punctuality (working days only), absences, lateness, leave and overtime, a daily
  trend, days by status and a department table. HR reads the rollup; managers, group filters and searches are
  calculated live for the viewer's people. Cached 60 s per workspace and viewer scope.
- Reports: attendance summary per employee, monthly register, late / early / absent / missing-punch days, leave
  taken per type, overtime per employee. At most 92 days on screen.
- Queued CSV and XLSX exports (`openspout/openspout`): requester-only downloads, formula-safe CSV, up to 366 days,
  at most 5 in progress per user, files deleted after 7 days by `reports:prune-exports` (scheduled daily).
- Permissions `reports.view` and `reports.export` (HR, Department Manager).
- Read methods for reports in `AttendanceQuery`, `LeaveQuery` and `OvertimeQuery`, and an `AttendanceDay` factory.
- SPA: Reports section with Dashboard (Recharts), Attendance summary, Monthly register, Late and absent, Leave taken,
  Overtime report and Exports; an Export menu on every report.
- 35 new Pest tests (431 total); OpenAPI spec v0.8.0.

### Changed

- `PermissionSyncTest` used `reports.view` as an example of a not-yet-registered permission; it now uses
  `sandbox.view`, because Module 8 registers `reports.view`.

### Added — Timetable break names

- Breaks take an optional `name` (for example "Lunch"), added by a new additive tenant migration. A name is a label,
  not a time rule, so it can change on a timetable that is in use; duplicates keep it. The demo timetables name
  their breaks.

### Added — Module 7: Overtime Management

- Overtime module (`app/Modules/Overtime`): overtime calculated from attendance days. Time after the shift counts,
  capped by the day's surplus over the scheduled time. Early arrival counts only when enabled. All work on rest
  days and holidays counts at their rates. Minimum, round-down step, daily cap and rates are in `overtime.*` settings.
- Calculation is idempotent: it runs in the attendance writer job through Attendance's new
  `AttendanceRecalculated` event. Decided records whose calculation changes go back to pending, flagged
  `changed_after_decision`.
- After-the-fact approval by a manager or HR (never one's own). Approvers can approve fewer minutes (weighted
  minutes in proportion) and approve in bulk. HR records manual overtime, approved at once when entered for
  someone else.
- Per-employee summary of approved, weighted and pending overtime. HR can recalculate a range with new settings.
- SPA: Overtime inbox, Overtime summary, My overtime, employee Overtime tab, overtime in the attendance day
  drawer, Overtime settings.
- `punches:simulate --overtime-rate` (share of people staying 35–120 minutes late).
- 16 new Pest tests (395 total); OpenAPI spec v0.7.0.

### Fixed

- OpenAPI spec: removed a duplicated `components` block that a merge had pasted into `POST /employees`
  (`employee_code` pattern) since v0.2.0. Also quoted an audit-log description containing `[redacted]`. The spec
  now parses and every `$ref` resolves.

### Added — Module 6: Leave Management

- Leave module (`app/Modules/Leave`): leave types (paid/unpaid, yearly grant or monthly accrual, carry-forward
  cap, negative limit, unlimited), requests in full and half days with a single approval step, cancellation and
  a team calendar.
- Balances are an append-only ledger (SQLite triggers): grants, accruals, carry-forward, expiry, request debits,
  cancellation credits, recounts and HR adjustments. Only scheduled working days count; holidays and days off
  are free. `php artisan leave:accrue` (scheduled hourly, idempotent) handles proration and the year end.
- Approved leave feeds attendance through Core's `LeaveCalendar` contract and `LeaveChanged` event: full days are
  `on_leave`; half days excuse that half and evaluate the other from the day's midpoint. Schedule and holiday
  changes recount leave automatically.
- SPA: My leave, Leave requests, Leave calendar, Leave types, employee Leave tab; "On leave" in attendance.
- 27 new Pest tests (379 total); OpenAPI spec v0.6.0.

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
