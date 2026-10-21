# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
