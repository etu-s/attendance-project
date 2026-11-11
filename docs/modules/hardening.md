# Module 9 — Hardening

Status: **built, awaiting approval** (2026-11-11)

Security, performance and operations work, plus the features earlier modules deferred here: payroll period locking,
leave attachments, password reset and invitations, and the live Today board. Production setup is in
[deployment.md](../deployment.md).

## Decisions (approved 2026-11-10)

1. Six parts, each committed on its own with tests: security · performance · payroll locking · leave attachments ·
   account emails · live updates and operations.
2. **Payroll locking**: HR and Tenant Admins lock, only a Tenant Admin unlocks (with a reason). Locked days are
   **frozen**: never recalculated, and changes that touch them are refused.
3. **Attachments**: PDF, JPG or PNG, at most 5 MB each and 3 per request.
4. **Emails**: password reset and invitations only; notification emails for decisions are a later addition.
5. Out of scope: PDF exports (would need dompdf), hourly leave, automatic cancellation of leave on termination.

## 1. Security

- **Security headers** on every response (`Core\Http\Middleware\SecurityHeaders`, appended globally):
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `Permissions-Policy`, `Cross-Origin-Opener-Policy`/`-Resource-Policy: same-origin`, and a CSP that allows nothing
  (`default-src 'none'; frame-ancestors 'none'`) because the API returns JSON and files only. HSTS is sent over HTTPS
  when `HSTS_MAX_AGE` > 0. The SPA's own CSP belongs to the web server (deployment guide).
- **Log masking** (`Core\Logging\MaskSensitiveData`, tapped onto every channel in `config/logging.php`): email
  addresses, punch source keys (`ams_…`), bearer tokens and long digit runs (phones, IDs; not dates) are masked in
  messages, context and exception text; context keys such as `password`, `token`, `secret`, `email`, `phone`,
  `first_name`, `last_name`, `employee_identifier` are replaced by `[masked]`.
- **Rate limits**: a `heavy` limiter (10/min per user, `HEAVY_RATE_LIMIT`) on report exports, attendance and overtime
  recalculation, CSV import, leave attachments and invitations; a `password` limiter (separate budget from login) on
  forgot / reset / accept-invitation; the public health endpoint is rate limited too.
- **Route audit test** (`Core/Tests/Feature/SecurityHardeningTest`): every `/api/*` route must be authenticated
  (except a reviewed list of public routes), rate limited, and in the right host group (tenant vs. central).
- **Dependencies**: `composer audit` advisories fixed with patch updates (Pest 5.3.5, PHPUnit 13.4.5, Symfony 8.1.x);
  `composer audit` is clean.
- `.env.example` starts with a production checklist.

## 2. Performance

- `AttendanceCalculator` fires **`AttendanceDaysChanged`** once per run with every changed date; the reports rollup
  refreshes each date once per run instead of once per employee (backfills were doing the same work N times).
- **Scoped recalculation**: a department, group or location schedule change recalculates only the people it can reach
  (history included, sub-departments included) via `AssignmentService::employeeIdsIn()` and
  `MembershipService::employeeIdsIn()`; organization-wide changes still recalculate everyone.
- **Index-usage tests** (`Reports/Tests/Feature/IndexUsageTest`) run `EXPLAIN QUERY PLAN` on the hot attendance,
  rollup, overtime, leave and export queries and fail on a full-table scan.
- **Code splitting**: report pages load on first visit; Echo/Pusher load only when Reverb is configured. The tenant
  bundle went from 687 kB to about 300 kB.

## 3. Payroll period locking (`app/Modules/Payroll`)

Core defines the **`PeriodLock`** contract (`lockedDates`, `assertOpen`, `overlapping`) with a no-op default; the
Payroll module implements it, like `LeaveCalendar`. Modules check it without depending on Payroll.

| Table | Notes |
| --- | --- |
| `payroll_periods` | `name`, `start_date`, `end_date`, `status` (locked / unlocked), `locked_by/at`, `unlocked_by/at`, `unlock_reason`; index (status, start_date, end_date); audited |

Rules (`PayrollPeriodService`): only finished days (end before today), at most 366 days, locked periods never overlap
(`409 period_overlap`). Unlocking fires Core's `PayrollPeriodUnlocked`; Attendance recalculates the period (so punches
that arrived while it was frozen are applied).

While locked (`409 period_locked`, message names the period):

| Area | Refused | Still allowed |
| --- | --- | --- |
| Attendance | corrections (request, approve) for a locked work date; the calculator skips locked dates | rejecting a pending correction; raw punches are stored (append-only) |
| Overtime | approve / reject, manual entries (create, edit, delete); batch approval skips locked records; the calculator skips locked dates | — |
| Leave | requests touching a locked day, approving them, cancelling approved leave | rejecting, withdrawing a pending request |
| Scheduling | assignments, temporary schedules and holidays whose changed days touch a locked period (end-date changes and yearly holidays are checked exactly) | ending an open-ended schedule after the period |

Endpoints: `GET payroll-periods` (`?status`) · `POST payroll-periods` · `POST payroll-periods/{id}/lock` ·
`POST payroll-periods/{id}/unlock` (`reason`). Permissions: `payroll-periods.view`, `payroll-periods.manage` (HR),
`payroll-periods.unlock` (Tenant Admin only). Reports list the locked periods in their range (`locked_periods`).

Screens: **Administration → Payroll periods** (lock a period, last month by default; unlock with a reason; lock again);
a "locked payroll period" note on every report.

## 4. Leave attachments

| Table | Notes |
| --- | --- |
| `leave_request_attachments` | `leave_request_id` (cascade), `original_name`, `mime_type`, `size_bytes`, `path` (tenant disk, random name), `uploaded_by`, `created_at`; audited (name only) |

- Content-checked (`mimes:pdf,jpg,jpeg,png`), ≤ 5 MB, ≤ 3 per request (`409 leave_attachment_limit`). The stored
  extension comes from the detected content; the shown name is the client's base name, cleaned.
- **Download**: the requester or the employee, their approvers, HR. Anyone else gets 404.
- **Add**: the requester or the employee, or HR (`leave.manage`), while the request is pending or approved.
- **Remove**: the uploader or HR, while pending. Files of a pending request that is cancelled are deleted.

Endpoints: `POST leave-requests/{id}/attachments` (multipart `file`) · `GET leave-requests/{id}/attachments/{file}` ·
`DELETE leave-requests/{id}/attachments/{file}`. Leave request resources include `attachments` and `can_attach`.

Screens: a paperclip button (with the file count) on every leave list opens the attachments dialog.

## 5. Password reset and invitations

- `POST auth/forgot-password` (workspace) and `POST central/auth/forgot-password` (console) answer `202` with the same
  message whether or not the email has an account; only active accounts get mail. Links expire after 60 minutes and
  work once (`auth/reset-password`, `central/auth/reset-password`).
- **Invitations**: `POST users` with `invite: true` (no password) creates the account with a password nobody knows and
  emails a link valid for 7 days (`auth/accept-invitation`); `POST users/{id}/invitation` resends it (replacing the
  old link; UserPolicy `update`; active accounts only).
- Tokens live in the workspace's own `password_reset_tokens` (super admins: central
  `super_admin_password_reset_tokens`). Brokers are built per call (`Core\Support\PasswordBrokers`) so a queue worker
  never reuses another workspace's connection. Setting a password rotates the remember token and revokes API tokens.
- Mails are queued Mailables (`Core\Mail\PasswordLinkMail`, `resources/views/emails/password-link.blade.php`) with links
  to `FRONTEND_URL` (workspace subdomain in front).

Screens: "Forgot your password?" on both sign-in pages; Forgot password, Choose a new password and Welcome (invitation)
pages; "Email an invitation" (default on) when adding a user; "Send invitation" in the user menu.

## 6. Live updates and operations

- **Live Today board**: after each recalculation run that changed today or yesterday, `TodayBoardChanged` is broadcast
  (queued) on the private channel `workspace.{subdomain}.attendance` (the subdomain, never the tenant id), authorised
  at `POST /api/v1/broadcasting/auth` for users with `attendance.view` in that workspace. The board refetches on the
  event and polls every 30 s only while no socket is connected. Broadcasting is off by default
  (`BROADCAST_CONNECTION=log`); a broken WebSocket setup is logged and never stops punch processing.
- **Central audit log**: `GET central/audit-logs` (filters as in workspaces; from/to are UTC days) and an Audit log page
  in the console.
- **Backups**: `php artisan db:backup --prune` is scheduled daily at 02:30; after a fully successful run it deletes
  backup folders older than `BACKUP_KEEP_DAYS` (14). Restore procedure: [deployment.md §5](../deployment.md).
- **Deployment guide**: processes, hosts and TLS, nginx sketch with the SPA's CSP, environment, deploy steps,
  backup and restore, monitoring ([deployment.md](../deployment.md)).

## Commands

```bash
php artisan db:backup --prune        # backups + retention (scheduled daily 02:30)
php artisan reverb:start             # WebSocket server for the live Today board (optional)
```

## Tests

New in this module: route audit and security headers, log masking, heavy rate limits; recalculation scope and the
batched event; index usage (8 queries); payroll locking (lock/unlock rules and permissions, frozen days and
recalculation on unlock, refusals across attendance, leave, overtime and scheduling, report notes, tenant isolation);
leave attachments (access, limits and types, removal rules, cleanup on cancel, tenant isolation); password reset,
invitations and super admin reset (no enumeration, expiry, single use, per-workspace tokens, email rendering); live
Today broadcasts (event, channel authorisation, resilience); central audit log; backup pruning. The Module 8 leave
report test now files its pending request through the real endpoint.

## Known limitations / follow-ups

- Setting a password does not sign out other browser sessions (file sessions); API tokens are revoked.
- No notification emails for leave, overtime or correction decisions (in-app only).
- PDF exports, hourly leave and automatic cancellation of leave on termination remain open.
- Locking does not freeze employee transfers or terminations themselves; their effect on locked days is frozen
  because the days are not recalculated.
- The live Today board needs a Reverb server; without it the board polls.
- In this Windows workspace the API process already running on port 8010 has no writable temp folder, so browser
  uploads fail there ("unable to create a temporary file"); start it with `TMP` set (see System Guide §15.2).
