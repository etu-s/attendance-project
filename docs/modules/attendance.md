# Module 5 — Punch Ingestion & Attendance Engine

Status: **approved** (2026-10-21)

Devices and integrations post punches; the system stores them as received, answers `202`, and turns them
into one attendance record per employee per work date on the queue, using the schedule from Module 4.
Everything calculated can be rebuilt from raw punches + approved corrections + schedule + settings.
Code: `app/Modules/Attendance`, `frontend/src/features/attendance`.

```
device ──POST /punches──▶ raw_punches (append-only) ──202
                              │ ProcessPunches (queue, one writer per tenant)
                              ▼
                 punch_matches / orphan_punches
                              │ AttendanceCalculator = ScheduleResolver + DayEvaluator
                              ▼
        attendance_days (+ attendance_periods, attendance_punches)
```

## Decisions (approved 2026-10-21)

1. Device in/out direction is ignored by default (first punch = in, last = out); `attendance.use_punch_direction`
   turns it on.
2. Late/early minutes count from the scheduled start/end once the grace is exceeded (09:12 for 09:00 with
   5 minutes grace = 12 late).
3. A batch stores its valid items and reports every item (`accepted`, `duplicate`, `invalid`).
4. Punches outside every window on a working day are kept as "unmatched" on their local date, not counted.
5. Corrections: employees request for themselves, managers and HR for people they see; HR and managers
   approve (never their own request, never about themselves); HR/Tenant Admin entries are approved at once.
6. Below a timetable's minimum work minutes the day is absent (worked minutes kept, flag `below_minimum`).
7. The Today board polls every 30 s; live push (Reverb) moves to Hardening.
8. One key per punch source; tenant from the subdomain; HMAC signature and IP allowlist optional per source.
9. Late-arriving punches count up to `attendance.backlog_days` (31) back; older ones are stored, marked
   `too_old`, never counted.

## Ingestion

- `POST /api/v1/punches`, `POST /api/v1/punches/batch` on the workspace subdomain,
  `Authorization: Bearer ams_<prefix>_<secret>`. Only `sha256(secret)` is stored; the key is shown once
  (create / rotate). Throttled per host + key prefix **before** authentication (`PUNCH_RATE_LIMIT`, 600/min).
- Signed sources also send `X-Timestamp` (unix seconds, ±5 min) and
  `X-Signature: sha256=hex(hmac_sha256("<timestamp>.<raw body>", signing secret))`.
- Payload: `employee_identifier` (employee code or punch identifier), `punched_at` (ISO 8601 **with offset**,
  ≤ 5 min in the future), optional `direction`, `idempotency_key`, `meta` (≤ 2 KB).
- Idempotency is in the schema: unique (source, identifier NOCASE, instant) and (source, idempotency key).
  A duplicate answers `202 {status: duplicate, id: <original>}`.
- `raw_punches` is append-only: SQLite triggers abort every UPDATE/DELETE (and the model refuses too).

## Processing

- `ProcessPunches` (unique per tenant until it starts) matches every raw punch past a cursor
  (`attendance_engine_state`), because raw rows cannot be flagged. Match = `punch_matches` (fixed at
  arrival: reassigning a badge later never moves old punches); unknown = `orphan_punches`.
- All attendance writers (`ProcessPunches`, `RecalculateAttendance`) share one `WithoutOverlapping` lock
  per tenant: one writer per SQLite file.
- Recalculation happens for: new punches (their UTC date ±1), `ScheduleChanged` (from Module 4; past
  dates only; non-employee scopes recalculate everyone employed in the range), approved corrections,
  linked orphans, `POST /attendance/recalculate`, and `attendance:close-days` every 15 minutes (today
  and the two days before, for everyone; a working day only becomes absent/incomplete once its last
  punch window has closed).
- Days whose result did not change are not rewritten (`fingerprint`), so the 15-minute job is cheap.
  The calculator loads everything per chunk of 50 employees up front: the query count does not grow
  with employees (tested).

## The rules (`DayEvaluator`)

- **Windows:** each scheduled period accepts punches from start − check-in-before to end +
  check-out-after (`TimetableRules`: timetable override, else `attendance.*` setting). A punch in several
  windows goes to the period whose start or end is nearest — so 02:00 after a 22:00 shift belongs to the
  previous work date, and split shifts separate cleanly.
- **Debounce:** within `attendance.debounce_seconds` (60) of the previous counted punch → `duplicate`.
- **In/out:** first/last punch of the period; a lone punch is the in or the out, whichever edge is nearer.
- **Fixed:** late/early from the scheduled edges beyond grace; worked = out − in − unpaid breaks (fixed
  breaks where they overlap the time worked, floating breaks whenever there is work), rounded down to
  `attendance.rounding_minutes`.
- **Flexible:** late/early against core hours (if any); worked below the required minutes counts as early
  leave (the shortfall).
- **Status (working day):** `in_progress` until the last window closes; then `absent` (no punches, or
  below minimum), `incomplete` (`missing_in` / `missing_out` / `missed_period`), `late_early`, `late`,
  `early_leave` or `present`.
- **No work expected:** `holiday` (periods kept: work is recorded, no late/early), `rest`, `day_off`,
  `unscheduled` (punches = first to last, flag `off_day_work`), `not_employed` (punches unmatched;
  no row unless there are punches).
- **Scheduled minutes** use real elapsed time (a DST night is 7 or 9 hours) minus unpaid breaks.

## Data model (tenant DB)

| Table | Notes |
| ----- | ----- |
| `punch_sources` | name, kind (device/integration), `key_prefix` unique, `key_hash`, `signing_secret` (encrypted), `ip_allowlist` (JSON), active, last used |
| `raw_punches` | source FK, identifier (NOCASE), `punched_at`, direction, idempotency key, meta, `too_old`, `received_at`; two unique indexes; **UPDATE/DELETE triggers abort** |
| `punch_matches` | raw punch → employee (PK raw id), copied `punched_at`, `matched_by` (auto/manual); index (employee, punched_at) |
| `orphan_punches` | raw punch (unique), identifier, status (open/resolved/dismissed), employee, decided by/at |
| `employee_punch_identifiers` | employee, identifier (NOCASE, unique; never equal to an employee code), label |
| `punch_corrections` | employee, work date, kind (add/ignore), punched_at or raw punch, reason, status, requested/decided by, note |
| `attendance_days` | unique (employee, work_date); status; department/location/shift/source/timezone snapshot; first in, last out; scheduled/worked/late/early minutes; unmatched count; flags; fingerprint; indexes (work_date, status) and (work_date, department) |
| `attendance_periods` | per scheduled period: timetable, scheduled start/end, in/out, worked/break/late/early |
| `attendance_punches` | how each punch was used: in/out/extra/duplicate/unmatched/ignored, period |
| `attendance_engine_state` | the processing cursor |

Instants are UTC `Y-m-d H:i:s`; work dates `Y-m-d`; durations whole minutes.

## Settings (Settings → Attendance)

`attendance.debounce_seconds` (60) · `attendance.rounding_minutes` (0) · `attendance.use_punch_direction` (off)
· `attendance.backlog_days` (31) · `attendance.max_batch_size` (500), next to Module 3's grace and window defaults.

## Endpoints and permissions

Ingestion: `punches`, `punches/batch` (source key). Admin: `punch-sources` (+ `rotate-key`) ·
`raw-punches` · `orphan-punches` (+ `link`, `dismiss`) · `employees/{id}/punch-identifiers`,
`punch-identifiers/{id}`. Attendance: `attendance` (filters date/from/to/status list/department/group/
employee/search) · `attendance/summary` · `attendance/{id}` · `attendance/recalculate` ·
`employees/{id}/attendance` · `me/attendance`. Corrections: `punch-corrections` (+ `approve`, `reject`).
See `docs/api/openapi.yaml` (v0.5.0).

| Permission | Default roles |
| --- | --- |
| `attendance.view` | HR, Department Manager (their people) |
| `attendance.manage` (recalculate) | HR |
| `punches.view`, `punches.manage` (log, unmatched, identifiers) | HR |
| `punch-sources.manage` | Tenant Admin only |
| `corrections.request` | HR, Department Manager, Employee |
| `corrections.approve` | HR, Department Manager |

Everyone may read their own days (`me/attendance`, and `attendance/{id}` for their own day).

## Screens

- **Today** — status tiles (click to filter), live list (30 s), any past date.
- **Attendance** — date range, department/status/search filters, recalculate range (HR).
- **Day drawer** (everywhere) — schedule vs punches, flags, corrections, "Request correction".
- **Corrections** — pending/approved/rejected; approve/reject with a note.
- **Punch log** — raw punches as received; **Unmatched punches** — link to an employee or dismiss.
- **Punch sources** — create, edit, rotate key, delete; key and secret shown once with a curl example.
- **Employee → Attendance tab** — month calendar + punch identifiers; **My attendance** — own month (mobile list).
- The sidebar is now grouped (Attendance, Scheduling, Organization, Administration).

## Commands

```bash
php artisan punches:simulate --tenant=acme [--from=Y-m-d --to=Y-m-d --seed=N --late-rate=0.15 --missing-rate=0.06]
php artisan punches:simulate --tenant=acme --http --key=<punch source key>   # through the real API + queue
php artisan attendance:close-days [--tenant=<id>] [--days=2]                 # also scheduled every 15 minutes
php artisan schedule:work                                                    # runs the scheduler locally
php artisan queue:work                                                       # processes punches (Windows)
```

The local demo workspace gets a "Main entrance" source and a week of simulated punches (`DemoAttendanceSeeder`).

## Tests

65 new Pest tests (352 total). Engine: on time/late/early/late+early, grace boundary, lone punch,
absent vs in-progress, debounce, unmatched, rounding, direction setting, minimum work, rest-day and
holiday work, overnight shift, DST night (New York), split shift, flexible core/shortfall, termination,
idempotent recalculation (no rewrite), schedule change → recalculation, corrections (add/ignore/pending),
backlog, flat query count. API: ingestion (offsets, duplicates by instant and key, strict validation,
orphans, keys, inactive, allowlist, HMAC incl. replay and tampering, rate limit, batches, append-only
triggers, tenant isolation with a raw SQLite file check); sources (secrets once, rotate, delete rules,
admin-only); raw log; orphan link/dismiss; identifiers; attendance list/summary/detail/ranges/
visibility/recalculate/isolation; corrections (employee/manager/HR flows, own-request rule, validation);
`punches:simulate` and `attendance:close-days`; the demo seeder.

## Known limitations / follow-ups

- Live updates poll (30 s); Reverb push is planned for Hardening.
- `ScheduleChanged` for a department/group/location recalculates everyone employed in the range (correct,
  but more work than needed for large workspaces; unchanged days are not rewritten).
- Organization does not check new employee codes against punch identifiers; if they collide, the employee
  code wins for new punches.
- A pending correction cannot be withdrawn by its requester (reject it instead).
- Times on the Corrections and Punch log lists are shown in the workspace timezone; the day drawer uses the
  employee's location timezone.
- Overtime (Module 7) will read worked minutes and off-day/holiday work from these records.

## Leave (added with Module 6)

Approved leave arrives through Core's `LeaveCalendar` contract and `LeaveChanged` event (see
[leave.md](leave.md)). On a working day, a full day of leave is `on_leave` (punches kept, minutes recorded, no
late/early, scheduled 0); a half day excuses that half and evaluates the other from the day's midpoint (flag
`half_day_leave`, scheduled and minimum work halved). Holidays inside leave stay holidays. Days record
`leave_name` and `leave_portion`.
