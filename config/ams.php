<?php

declare(strict_types=1);

/*
 * Application-level (non-business) configuration. Business rules that tenants
 * can change (grace periods, rounding, OT rules, ...) belong in the tenant
 * `settings` table instead, never here.
 */
return [

    // Subdomains a tenant may never claim because they collide with app hosts.
    'reserved_subdomains' => ['www', 'api', 'app', 'admin', 'central', 'mail', 'static', 'assets', 'status'],

    'pagination' => [
        'default_per_page' => 15,
        'max_per_page' => 100,
    ],

    'backups' => [
        // Absolute path; resolved before entering tenant context because the
        // filesystem bootstrapper suffixes storage_path() per tenant.
        'path' => env('BACKUP_PATH', storage_path('backups')),
    ],

    // Local development seed accounts (php artisan migrate --seed). Never used in production.
    'seed' => [
        'super_admin_email' => env('SEED_SUPER_ADMIN_EMAIL', 'admin@ams.test'),
        'super_admin_password' => env('SEED_SUPER_ADMIN_PASSWORD', 'password'),
        'demo_admin_email' => env('SEED_DEMO_ADMIN_EMAIL', 'admin@acme.test'),
        'demo_admin_password' => env('SEED_DEMO_ADMIN_PASSWORD', 'password1'),
    ],

    // Reports & Analytics limits (application limits, not business rules).
    'reports' => [
        // Longest range shown on screen, and written to an export file.
        'max_view_days' => 92,
        'max_export_days' => 366,
        // How long a dashboard result is cached (0 = not cached).
        'dashboard_cache_seconds' => (int) env('REPORTS_DASHBOARD_CACHE_SECONDS', 60),
        // Export files are deleted after this many days (reports:prune-exports).
        'export_ttl_days' => 7,
        // Queued or running exports one user may have at a time.
        'max_pending_exports' => 5,
    ],

    'rate_limits' => [
        'login_per_minute' => (int) env('LOGIN_RATE_LIMIT', 5),
        'api_per_minute' => (int) env('API_RATE_LIMIT', 120),
        // Per punch source key on one tenant host (POST /punches, /punches/batch).
        'punches_per_minute' => (int) env('PUNCH_RATE_LIMIT', 600),
    ],

];
