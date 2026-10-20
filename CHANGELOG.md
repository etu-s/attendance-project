# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
