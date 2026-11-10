# Module 8 — Reports & Analytics

Status: **built, awaiting approval** (2026-11-10)

A dashboard and five tabular reports over calculated attendance, leave and overtime, with queued CSV/XLSX exports.
Code: `app/Modules/Reports`, `frontend/src/features/reports`; read methods added to `Attendance\Services\AttendanceQuery`,
`Leave\Services\LeaveQuery` and `Overtime\Services\OvertimeQuery` (Reports never queries their tables).

## Decisions (approved 2026-10-28)

1. **Rollup for HR, live for managers.** `attendance_daily_stats` holds one row per work date × department × location
   (as recorded on the day). It is used when the viewer sees everyone (Tenant Admin, HR) and the filters are ones the
   rollup has (range, department, location). Department managers, a group filter or a name search aggregate live over
   attendance days, scoped by `EmployeeVisibility`. Both paths use the same column definitions
   (`AttendanceQuery::aggregateColumns()`), and a test proves they agree.
2. **KPIs** (`Reports\Support\Kpis`):
   - *attended* = present + late + early_leave + late_early + incomplete;
   - *working days* = attended + absent (leave, holidays, rest days, days off and days in progress are left out);
   - *attendance rate* = attended ÷ working days;
   - *punctuality* = attended days without late minutes ÷ attended.
   Rates are percentages with one decimal, `null` when there is nothing to divide. Days outside employment
   (`not_employed`) are never counted.
3. **Exports**: CSV built in, XLSX through `openspout/openspout`; PDF deferred to Hardening. Always queued
   (`report_exports`), downloadable by the requester only, files expire after 7 days.
4. **Recharts** for the dashboard chart.
5. **Payroll period locking** deferred to Hardening.
6. **Caching**: the dashboard result is cached 60 s (`REPORTS_DASHBOARD_CACHE_SECONDS`); the store is tenant-tagged by
   stancl's cache bootstrapper and the key carries the viewer scope (everyone vs. that user). Local dev keeps the
   `array` store; production uses Redis.
7. Ranges are capped at **92 days on screen** and **366 days in an export** (`config/ams.php` → `reports`).

## How the rollup stays in sync

- `RefreshDailyStats` listens to Attendance's `AttendanceRecalculated` **synchronously**, so the rollup is written
  inside the attendance writer job (one writer per tenant, like overtime) and is never behind attendance.
- `DailyStatsBuilder::refresh($dates)` rebuilds whole dates: it reads the aggregates and deletes/inserts that date's rows
  in one short `IMMEDIATE` transaction (31 dates per transaction at most). A day that moved department after a
  backdated transfer therefore leaves no stale row, and running it again changes nothing.
- `php artisan reports:rebuild-stats [--tenant=] [--from=] [--to=]` queues `RebuildDailyStats` (same writer lock) for
  every calculated date by default. **Run it once on any workspace that had attendance before Module 8.**
- 0 stands for "no department/location" in the rollup, because SQLite treats NULLs as distinct in a unique index.

## Data model (tenant DB, migration `2026_11_10_000900`)

| Table | Notes |
| --- | --- |
| `attendance_daily_stats` | `work_date`, `department_id`, `location_id` (0 = none), `days`, one count per status (present … unscheduled), `half_day_leave`, `late_days`, `scheduled_minutes` (attended + absent days), `worked_minutes`, `late_minutes`, `early_leave_minutes`, `refreshed_at`; unique (work_date, department_id, location_id); index (department_id, work_date). Derived data, never edited by hand |
| `report_exports` | `report`, `format` (csv/xlsx), `params` (JSON, the validated report parameters), `status` (queued → running → completed → expired, or failed), `requested_by` (FK users, cascade), `file_name`, `path` (tenant local disk), `row_count`, `size_bytes`, `error` (user-safe), `started_at`, `completed_at`, `expires_at`; indexes (requested_by, created_at), (status, expires_at) |

## Reports

Every report is a `Reports\Reports\Report`: validation rules, sort keys, `page()` (the JSON rows) and `columns()` (the
export columns over the same rows). The API and the export job share them, so a file always matches the screen.

| Report (`GET /reports/{report}`) | Rows | Parameters | Sort |
| --- | --- | --- | --- |
| `attendance-summary` | one per employee with days in the range: KPIs, days and minutes | from, to, department, location, group, search | name, absent_days, late_days, late_minutes, early_leave_minutes, worked_minutes |
| `register` | one per employee: a status cell per day of the month (code, half-day leave, late/early/worked minutes) and month totals; `meta.dates`, `meta.codes` | **month** (Y-m), department, location, group, search | name |
| `exceptions` | one per employee and day | **type**: `late` (late, late_early), `early_leave` (early_leave, late_early), `absent`, `incomplete`; range and filters | work_date, late_minutes, early_leave_minutes, worked_minutes |
| `leave` | one per employee: approved half days taken in the range, in total and per type; `meta.leave_types` | from, to, department, `leave_type_id`, search | name, half_days |
| `overtime` | one per employee (Overtime's summary): approved, weighted, per kind, pending | from, to, department, search | approved_weighted_minutes (desc) |

Register codes: P present · L late · E left early · LE late and left early · I missing punch · A absent · W in progress ·
LV on leave · H holiday · R rest day · O day off · U no schedule; `½` marks half a day of leave.

Leave counts only the days a request counted (scheduled working days, clipped to the range). Leave and overtime have
no location or group placement, so those filters do not apply; their department filter uses the current department.
Exception exports show punch times in the day's own timezone.

## Dashboard (`GET /reports/dashboard?from&to[&department_id&location_id&group_id]`)

`source` (`rollup` / `live`), `kpis` (Kpis plus overtime approved / weighted / pending minutes and pending count),
`statuses` (days per status), `trend` (every date of the range, zero-filled: on time, late, absent, on leave, rate) and
`departments` (one row per department recorded on the days, most working days first).

## Exports

`POST /reports/exports` `{report, format, ...parameters}` → **202** with the export. `GenerateReportExport` (not
retried; a failure shows as `failed` with a generic message, details go to the log) pages through the report 500 rows
at a time and streams the file to `storage/tenant<id>/app/report-exports/`. The requester's visibility is applied as it
is at generation time; a user who lost `reports.export` gets a failed export.

- CSV: UTF-8 with a BOM; text starting with `=`, `+`, `-`, `@`, tab or CR is prefixed with `'` (formula injection).
  XLSX writes strings as string cells.
- File names: `<report>_<from>_<to>.<ext>` or `register_<month>.<ext>`.
- At most 5 queued or running exports per user (`429 export_limit`).
- `reports:prune-exports` (scheduled daily) deletes expired files and marks the rows `expired`; rows stay as history.

| Endpoint | Notes |
| --- | --- |
| `GET /reports/exports` | the user's own exports, newest first |
| `POST /reports/exports` | 202; validation as the report plus `report`, `format`; ≤ 366 days |
| `GET /reports/exports/{id}` | 404 unless it is the user's own |
| `GET /reports/exports/{id}/download` | the file; `409 export_not_ready`, `410 export_expired`, 404 for others |

## Permissions

| Permission | Default roles |
| --- | --- |
| `reports.view` (dashboard, reports) | HR, Department Manager (their people) |
| `reports.export` (exports page and files) | HR, Department Manager |

Tenant Admin has every permission. Filters never widen what a viewer sees.

## Screens

Sidebar section **Reports**: **Dashboard** (last 30 days by default; six KPI tiles, a stacked daily chart with legend,
tooltips and a table view, days by status, the department table), **Attendance summary**, **Monthly register**
(month picker, colour-tinted codes, sticky name column), **Late and absent** (tabs per type), **Leave taken**,
**Overtime report** and **Exports** (status refreshes every 3 s while a file is being built; download link). Every
report page has an **Export** menu (Excel / CSV) when the user holds `reports.export`. Filters live in the URL.

Chart colours are the workspace status tokens. In light mode late (amber) is too close to present and absent for
colour-blind readers (checked with the palette validator), so the late segment is also hatched; identity never relies
on colour alone.

## Commands

```bash
php artisan reports:rebuild-stats                  # queue a rollup rebuild for every calculated date (all tenants)
php artisan reports:rebuild-stats --tenant=<id> --from=2026-01-01 --to=2026-03-31
php artisan reports:prune-exports                  # delete expired export files (scheduled daily)
```

## Tests

35 new Pest tests (431 total). Rollup: kept in step by punches through the writer (present, late, missing check-out),
a day that moved department leaves no stale row, idempotent refresh, overnight shifts on their start date, rollup
equals the live aggregate (incl. half-day leave, not_employed excluded), rebuild command (default range, invalid date).
Dashboard: HR KPIs/trend/status mix/departments from the rollup, managers live and scoped (a filter cannot widen it),
department/location on the rollup and group live, caching per viewer scope with expiry, validation and permission.
Reports: summary totals, sorting, search, filters, manager scope, flat query count from 2 to 12 employees; register
grid and month validation; exceptions per type with sorting and scope; leave per type (pending excluded, range
clipping); overtime; unknown report 404; range cap. Exports: CSV download with BOM and formula escaping, XLSX and CSV
for every report, register layout, privacy (404 for others, 403 without permission), expiry and pruning (410),
validation, the 366-day cap, the in-progress limit, generation refused after losing the permission. Unit: KPI
formulas, exception mapping, CSV cell escaping, parameter round trip. Tenant isolation: rollup rows, exports and files
of one tenant are absent from the other's SQLite file and disk.

## Known limitations / follow-ups

- A large backfill rebuilds each affected date once per recalculated employee (correct, but repeated work); batching
  the refresh per writer job is a Hardening item.
- Leave and overtime reports ignore location and group filters and use the current department.
- The department table lists sub-departments as their own rows (no roll-up into parents).
- No PDF export, no scheduled or emailed reports, no saved report views.
- No payroll period locking (deferred to Hardening).
- Dashboard numbers can be up to a minute old (cache).
- `composer audit` reports 8 advisories in 5 packages that were already installed (phpunit/phpunit and
  symfony/http-foundation, http-kernel, mime, routing); none in openspout. To review in Hardening.
