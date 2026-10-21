# CLAUDE.md

Persistent rules for every Claude Code session on this project: a multi-tenant Attendance Management System (Laravel API + React SPA). Read this file at the start of each session.

## Project summary

- Accepts real-time punch timestamps from external API endpoints and evaluates them against each employee's resolved work schedule.
- Manages organization, departments, groups, employees, timetables, breaks, shifts (fixed/flexible), schedules (department/group/employee), temporary schedules, leave, overtime, and reports/analytics.
- Modular, multi-tenant, modern and simple UI.

## Stack

- **Backend:** Laravel (latest stable), PHP 8.3+, **SQLite** (WAL mode), Redis, Sanctum, Horizon, Reverb, Pest
- **Frontend (`/frontend`):** React + TypeScript (strict) + Vite, Tailwind, shadcn/ui, TanStack Query, React Router, React Hook Form + Zod, Recharts
- **Tenancy:** `stancl/tenancy`, database-per-tenant (**one SQLite file per tenant**), subdomain identification. Central SQLite DB holds tenants, domains, plans.
- **Permissions:** `spatie/laravel-permission`
- **API:** versioned under `/api/v1`, JSON only

## Workflow rules (most important)

1. **Work one module at a time**, in this order: Foundation → Organization & Employees → Timetable & Shifts → Scheduling → Punch Ingestion & Attendance Engine → Leave → Overtime → Reports & Analytics → Hardening.
2. **Never start the next module until I explicitly approve the current one.**
3. **Plan before coding.** For each module, first post a short plan (entities, endpoints, screens, edge cases, open questions) and wait for approval if anything affects the data model.
4. **Ask, don't guess,** when a requirement is ambiguous and touches the schema, business rules, or tenancy.
5. **End every module with a summary:** what was built, how to run it, decisions made, known limitations, and any TODOs. Then stop.
6. Keep changes scoped. Do not refactor or modify unrelated modules without asking.
7. Never leave silent TODOs, commented-out code, or placeholder logic. List unfinished items in the summary.

## Definition of done (per module)

- [ ] Migrations, models, factories, seeders
- [ ] Services, policies, controllers, Form Requests, API Resources
- [ ] Pest tests: unit (business rules), feature (every endpoint), tenant isolation
- [ ] React pages, components, hooks, API client, loading/empty/error states
- [ ] Docs updated: `/docs/modules/<module>.md`, API docs, changelog entry
- [ ] `composer test`, Pint, ESLint, `tsc --noEmit` all pass
- [ ] Summary delivered, waiting for approval

## Architecture rules

- **Modular monolith:** `app/Modules/<ModuleName>/` containing its own models, migrations, controllers, requests, resources, services, policies, events, jobs, routes, tests.
- Modules communicate only through **service classes and events**. Never query another module's tables directly.
- **Thin controllers.** Business logic lives in service/action classes. Validation in Form Requests. Responses via API Resources.
- Authorize every endpoint with a Policy or permission check. Scope data by role (managers see only their departments/groups).
- Roles: Super Admin (central), Tenant Admin, HR Manager, Department Manager, Employee.
- Configurable behavior (grace periods, rounding, debounce window, OT rules) lives in settings tables, never hard-coded.
- Use enums for statuses and types. Use DB transactions for multi-step writes.
- Audit-log create/update/delete on sensitive entities (who, what, before/after).
- Soft deletes where history matters (employees, leave, attendance).

## Multi-tenancy rules

- Every tenant-scoped query must run inside tenant context. No cross-tenant access, ever.
- Central DB tables (tenants, domains, plans, super admins) stay separate from tenant tables.
- Every module ships with a **tenant isolation test**.
- Queued jobs, events, broadcasts, and cache keys must be tenant-aware.
- Never put tenant IDs or secrets in logs or error messages exposed to clients.

## Database rules (SQLite)

- SQLite is the only supported database. Do not write MySQL- or PostgreSQL-specific SQL (no `JSON_TABLE`, `FILTER`, `DATE_FORMAT`, stored procedures, or native enum/array types). Use Eloquent, the query builder, and portable SQL.
- **Storage layout:** `database/central.sqlite` for central data, `database/tenants/<tenant_id>.sqlite` for each tenant. Tenant files are created, migrated, and deleted through the tenancy package, never by hand.
- **Every connection must be configured with:** `journal_mode=WAL`, `foreign_keys=ON`, `busy_timeout` (at least 5000 ms), `synchronous=NORMAL`. Set these in `config/database.php` or a connection resolver, and verify them in a test.
- **Write concurrency:** SQLite allows one writer at a time per file. Keep write transactions short, never hold a transaction open across HTTP calls or slow work, and do heavy writes (punch processing, recalculation, imports) in queued jobs. Process punches for one tenant sequentially (queue per tenant, or a rate-limited/unique-job approach) to avoid `database is locked` errors.
- **Raw punch ingestion** must stay fast: insert the raw punch, return `202`, process later. Use batch inserts inside a single transaction for `/punches/batch`.
- **Types:** SQLite is loosely typed. Define columns with explicit Laravel types, cast in models (enums, dates, booleans, JSON, decimals), and store money and durations as integers (minor units, minutes or seconds), never floats.
- **Dates:** store UTC as `Y-m-d H:i:s` text consistently so string comparison and indexes behave correctly. Store work dates as `Y-m-d`.
- **Migrations:** SQLite has limited `ALTER TABLE`. Keep migrations simple, avoid dropping or altering columns in place where possible, and make sure each migration runs on a fresh database and on an existing one. Do not rely on foreign-key changes after table creation.
- **Constraints:** enforce uniqueness and integrity in the schema (unique indexes for idempotency keys and `employee_id + work_date`, foreign keys, check constraints where useful), not only in PHP.
- **Search and reports:** use indexes and covering indexes for attendance queries. Case-insensitive search uses `LIKE` or a `COLLATE NOCASE` column. Add FTS5 only if search proves slow, and ask first.
- **Backups:** back up with `VACUUM INTO` or the SQLite online backup API, not by copying a live file. Keep a backup command in the commands list.
- **Tests:** use in-memory SQLite or temporary files for Pest. Tenant isolation tests must confirm that tenant A's file contains none of tenant B's data.
- **Scaling note:** if write contention or file size becomes a real problem, raise it with me and propose options rather than quietly changing the database.

## Time and date rules

- Store all timestamps in **UTC**. Convert using the tenant timezone (location may override).
- Never compare dates as strings. Use Carbon with explicit timezones.
- Handle **overnight shifts, midnight punches, and DST transitions** explicitly, and test them.
- An attendance "work date" is derived from the resolved shift, not the raw punch calendar date.

## Core domain rules

- **Schedule precedence** (single service, heavily tested): temporary employee schedule > employee schedule > group schedule (by group priority) > department schedule > organization default.
- **Punches are append-only.** Store the raw punch immediately, return `202 Accepted`, process via queue. Never edit or delete raw punches. Corrections are separate records with approval and audit trail.
- **Idempotency:** duplicate punches (same device + identifier + timestamp, or same idempotency key) must not create duplicate records.
- **Attendance is recalculable** from raw punches + schedule + rules. Recalculation must be safe to run repeatedly.
- **Leave balance is a ledger.** Never mutate a balance directly. Every credit or debit is a transaction.
- Approved leave, holidays, and weekly offs feed into attendance status.
- Overtime is calculated from attendance records, then goes through approval.

## Punch ingestion API rules

- Endpoints: `POST /api/v1/punches` and `POST /api/v1/punches/batch`.
- Auth via per-tenant API key or device token, optional HMAC signature and IP allowlist. Rate limit all ingestion routes.
- Validate payload strictly. Reject unknown employee identifiers into an orphan-punch queue instead of dropping them.
- Keep the OpenAPI spec in sync with the code. Keep `php artisan punches:simulate` working.

## Testing rules

- Pest for backend. Every endpoint has a feature test. Every business rule has a unit test.
- Mandatory edge cases: overnight shifts, DST, duplicate punches, missing punches, mid-period schedule changes, backdated corrections, leave overlapping holidays, negative balances.
- Use factories, not hand-built rows. Tests must not depend on execution order or real time (freeze time).
- Do not weaken or delete a failing test to make it pass. Fix the cause or ask me.

## Performance rules

- No N+1 queries. Eager load relationships and verify with query counts in tests where it matters.
- Index attendance queries (employee_id + work_date, status, department). Review every new migration for indexes.
- Use queues for anything slow (punch processing, recalculation, exports, emails). Keep SQLite write transactions short (see Database rules).
- Use database-level aggregation and daily summary tables for reports. Cache dashboards with tenant-aware keys.

## Security rules

- Validate all input. Never trust client-supplied tenant, role, or employee IDs without authorization checks.
- Never commit secrets. Use `.env` and `.env.example`.
- Hash API keys at rest. Show them once on creation.
- Use rate limiting on auth and ingestion routes.
- Mask sensitive personal data in logs.

## Frontend rules

- TypeScript strict. No `any` unless justified with a comment.
- Use shadcn/ui components and Tailwind design tokens. Do not introduce another UI library without asking.
- Server state via TanStack Query. Forms via React Hook Form + Zod, with schemas mirroring backend validation.
- Every page has loading (skeleton), empty, and error states.
- Tables use server-side pagination, sorting, and filtering.
- Support light/dark mode, responsive layouts (employee views must work on mobile), and keyboard accessibility (WCAG AA).
- Role-based UI: hide or disable actions the user cannot perform, but always enforce permissions on the backend.
- Style: modern, minimal, plenty of whitespace, consistent status colors (present, late, absent, leave, holiday).

## Code style

- PHP: Laravel Pint, strict types, typed properties and return types.
- TypeScript: ESLint + Prettier.
- Naming: models singular (`Employee`), tables plural snake_case (`employees`), enums PascalCase, React components PascalCase, hooks `useXxx`.
- Comments explain **why**, not what. Document non-obvious business rules next to the code.

## Git rules

- Conventional Commits (`feat(scheduling): ...`, `fix(attendance): ...`, `test(leave): ...`).
- One logical change per commit. Do not commit failing code.
- Do not force-push, rewrite history, or touch other branches without asking.

## Commands (update as the project evolves)

```bash
# Backend
composer setup                       # install, .env, key, central.sqlite, tenants dir, migrate --seed
composer install
touch database/central.sqlite && mkdir -p database/tenants
php artisan migrate --seed           # central schema + plans (+ super admin and demo tenant "acme" in local)
php artisan tenants:migrate          # migrate all tenant SQLite files
php artisan tenants:seed --force     # re-sync roles/permissions + missing settings in every tenant (idempotent)
php artisan db:backup                # VACUUM INTO backups of central + tenants -> storage/backups/<timestamp>/
php artisan serve
php artisan horizon                  # Linux only (needs pcntl); on Windows use: php artisan queue:work
php artisan reverb:start             # installed, not configured yet
php artisan punches:simulate         # Module 5
composer test          # Pest
./vendor/bin/pint      # format

# Frontend
cd frontend
npm install
npm run dev
npm run lint
npm run typecheck
npm run build
```

## Things to never do

- Never bypass tenancy scoping or policies to "make it work".
- Never delete or overwrite raw punch data.
- Never hard-code business thresholds (grace minutes, OT multipliers, rounding).
- Never run destructive commands (`migrate:fresh`, dropping tables, deleting data) outside local dev without asking.
- Never install new major dependencies without explaining why and getting approval.
- Never skip tests or linters to finish faster.
- Never commit `.sqlite` files, WAL/SHM files, or tenant databases. Keep `database/*.sqlite`, `database/tenants/`, and `*.sqlite-wal` / `*.sqlite-shm` in `.gitignore`.
- Never add MySQL/PostgreSQL-only SQL or switch the database engine without asking.
- Never copy a live SQLite file as a backup. Use `VACUUM INTO` or the online backup API.

## Session start checklist

1. Read this file and `/docs` for current module status.
2. Check which module is in progress and what was last approved.
3. Run the test suite to confirm a clean baseline.
4. Continue only with the approved module. If unsure, ask.

## Module status (update after each approval)

| #   | Module                              | Status      |
| --- | ----------------------------------- | ----------- |
| 1   | Foundation                          | Approved (2026-10-20) |
| 2   | Organization & Employees            | Approved (2026-10-21) |
| 3   | Timetable & Shifts                  | Approved (2026-10-21) |
| 4   | Scheduling                          | Planning    |
| 5   | Punch Ingestion & Attendance Engine | Not started |
| 6   | Leave Management                    | Not started |
| 7   | Overtime Management                 | Not started |
| 8   | Reports & Analytics                 | Not started |
| 9   | Hardening                           | Not started |
