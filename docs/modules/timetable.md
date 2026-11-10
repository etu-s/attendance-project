# Module 3 — Timetable & Shifts

Status: **approved** (2026-10-21)

What a working day looks like. **Timetables** are work periods; **shifts** repeat them over a day or
week cycle. Module 4 (Scheduling) assigns shifts to departments, groups and employees with a cycle start
date; Module 5 evaluates punches against the periods this module produces.
Code: `app/Modules/Timetable`, `frontend/src/features/timetables`.

## Decisions (approved 2026-10-21)

1. Shift cycles are weekly **and** N-day rotations; the cycle start (anchor) is set when scheduling.
2. Split shifts: up to 3 timetables per cycle day, never overlapping.
3. Breaks are fixed-time or floating, each paid or unpaid. Break punches are evaluated in Module 5.
4. Grace minutes and punch windows: workspace defaults in Settings, nullable per-timetable override.
5. Time rules of a timetable/shift **in use by a schedule are read-only**; duplicate and reschedule instead.
6. The work date of an overnight period is the date it **starts** (Mon 22:00–Tue 06:00 → Monday).
7. Flexible timetables have optional core hours.
8. The holiday calendar belongs to Module 4.

## Data model (tenant DB)

All times are **whole minutes from local midnight** (09:30 = 570). A timetable has no timezone; it gets one
when applied to a date.

| Table              | Notes |
| ------------------ | ----- |
| `timetables`       | `code` NOCASE unique, `type` fixed/flexible, `start_minute` (0–1439), `duration_minutes` (1–1440; fixed = shift, flexible = window), flexible `required_minutes` + optional core hours (offset + duration), nullable `late_grace_minutes`, `early_leave_grace_minutes`, `check_in_before_minutes`, `check_out_after_minutes` (null = workspace default), `min_work_minutes`, `color`, `is_active` |
| `timetable_breaks` | `kind` fixed/floating, `start_offset` (fixed: minutes after start), `duration_minutes`, `is_paid`, `position`, optional `name` (a label such as "Lunch"; not a time rule, so it can change while the timetable is locked); cascade with the timetable |
| `shifts`           | `code` NOCASE unique, `cycle_unit` day/week, `cycle_length` (≤ 56 days / ≤ 8 weeks), `description`, `is_active` |
| `shift_days`       | (shift, `day_index`, timetable) unique; timetable FK **restrict** (a timetable in a shift cannot be deleted) |

New settings (group `attendance`): `late_grace_minutes` (5), `early_leave_grace_minutes` (5),
`check_in_before_minutes` (120), `check_out_after_minutes` (240).

## Services other modules use

- `ShiftCalendar::expand($shift, $anchor, $from, $to, $timezone)` → for every date, the
  `ScheduledPeriod`s (timetable, UTC `startsAt`/`endsAt`, real `minutes()`). `cycleIndex()` gives the cycle
  day of a date. **No other code should do cycle arithmetic.**
- `TimetableRules::for($timetable)` → grace and punch windows after workspace defaults. The attendance engine
  must read thresholds only through this.
- `UsageRegistry::whenShiftInUse(fn (int $shiftId): bool)` / `whenTimetableInUse(...)` → Module 4 registers
  "a schedule uses this shift"; this module then refuses time-rule changes (`timetable_locked`,
  `shift_locked`) and deletion (`shift_in_use`). **No checker is registered yet**, so nothing is locked until
  Module 4 exists; the lock itself is implemented and tested with a test checker.

## Calendar rules

- Day cycles count from the anchor date (negative offsets work, so dates before the anchor resolve too).
- Week cycles count whole weeks from the **Monday** of the anchor's week; `day_index` 0 is that Monday,
  regardless of the workspace "first day of week" display setting.
- Wall-clock times are resolved in the given timezone, so on DST days the real length differs: in
  Europe/Berlin a 22:00–06:00 night is 7 h on 2026-03-28 and 9 h on 2026-10-24. A start inside the skipped
  hour moves forward by the gap (02:30 → 03:30); an ambiguous wall time resolves to its first occurrence.

## Validation

- **Timetable:** flexible needs required time ≤ window; core hours both or neither, inside the window; fixed
  breaks need a start strictly inside the timetable and must not overlap each other; flexible timetables
  have floating breaks only; total breaks must fit (fixed: shorter than the timetable; flexible: breaks +
  required ≤ window); grace and minimum worked ≤ the timetable; at most 4 breaks.
- **Shift cycle:** at most 3 active timetables per day; day indexes inside the cycle; no overlaps, including
  an overnight period running into the next day and the last day running into day 0 of the next cycle.
  Errors are reported per day (`days.<index>`). Shortening a cycle cannot cut off working days.

## Endpoints

`timetables` (CRUD, `POST {id}/duplicate`) · `shifts` (CRUD, `PUT {id}/days`, `POST {id}/duplicate`,
`GET {id}/preview?from&to[&anchor][&timezone]`, max 62 days). Single-timetable responses carry
`meta.locked` and `meta.effective`; shift responses carry `meta.locked`. See `docs/api/openapi.yaml` (v0.3.0).

Permissions: `timetables.view` / `shifts.view` (HR, Department Manager), `timetables.manage` /
`shifts.manage` (HR); Tenant Admin has all.

## Screens

- **Timetables:** list (hours, type, work time, breaks, shifts using it) and an editor with a live 24-hour
  timeline (work or window, core hours, fixed breaks, punch windows; overnight wraps), breaks editor, grace
  overrides showing the workspace default as placeholder, lock banner with Duplicate.
- **Shifts:** list with a colour strip of the cycle; detail page with the cycle editor (add/remove up to 3
  timetables per day, per-day server errors, save/discard) and a 28-day preview from a chosen start date in
  the workspace timezone.
- **Settings:** new "Attendance defaults" card.

## Tests

52 new Pest tests (243 total): calendar expansion (Mon–Fri week, overnight work date, DST spring/autumn
lengths, skipped-hour start, 4-on/4-off across a month end and before the anchor, two-week anchoring,
split-shift ordering, range limits), every endpoint, every validation rule, defaults and overrides, lock
via the usage registry, duplicate codes, delete guards, N+1 check on the shift list, permissions, tenant
isolation (raw SQLite file check). Plus a regression test that runs the real local demo seeder chain.

## Fixed in passing

`DemoTenantSeeder` (Central, committed with Module 2) was missing the import of `DemoOrganizationSeeder`,
so a fresh `composer setup` failed in local. Fixed, with `DemoSeederTest` guarding it.

## Known limitations / follow-ups

- Nothing is locked until Module 4 registers schedule usage (see above).
- `min_work_minutes` and break kinds are stored and validated; their effect on attendance status is Module 5.
- No per-location or per-department default timetable; "organization default" schedule comes with Module 4.
