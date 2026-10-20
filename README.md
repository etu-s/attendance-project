# AMS — Attendance Management System

Multi-tenant attendance platform: a Laravel API (one SQLite database per tenant) and a React SPA.

- Project rules and module plan: [CLAUDE.md](CLAUDE.md)
- Module docs: [docs/modules](docs/modules) · API: [docs/api/openapi.yaml](docs/api/openapi.yaml) · [CHANGELOG.md](CHANGELOG.md)

## Requirements

PHP 8.3+ (pdo_sqlite, mbstring, intl), Composer 2, Node 20+, Redis (optional locally; see `.env.example`).

## Quick start

```bash
composer setup
php artisan serve
cd frontend && npm install && npm run dev
```

Open http://localhost:5173 for the super admin console (`admin@ams.test` / `password`) and
http://acme.localhost:5173 for the demo tenant (`admin@acme.test` / `password1`). These are local seed
accounts defined in `.env` (`SEED_*`).

## Quality gates

```bash
composer test                     # Pest
./vendor/bin/pint --test          # PHP style
cd frontend && npm run lint && npm run typecheck && npm run build
```
