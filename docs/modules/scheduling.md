# Module 4 — Scheduling

Status: **approved** (2026-10-21)

Which shift applies to whom on which date. One service, `ScheduleResolver`, answers "what is employee E
scheduled to do on date D, and why"; Module 5 evaluates punches against its answer.
Code: `app/Modules/Scheduling`, `frontend/src/features/scheduling`.

## Decisions (approved 2026-10-21)

1. A temporary schedule is "work shift X (from an anchor) for these dates" or "days off".
2. A sub-department without its own schedule inherits from its nearest ancestor department.
3. The cycle start (anchor) defaults to the assignment's start date and can be changed.
4. Shifts/timetables become read-only once a schedule using them has **started** (start ≤ today in the
   workspace timezone); future-only use does not lock.
5. Backdated changes are allowed: they split history, are audited and fire `ScheduleChanged`.
6. Holidays are workspace-wide or per location, optionally recurring yearly on a fixed date.
7. A read-only roster (≤ 31 days) is part of this module.
8. A temporary schedule beats a holiday (so HR can schedule holiday cover).

## Precedence (`ScheduleResolver`)

1. **Temporary** schedule (shift or day off) — beats holidays
2. **Employee** schedule
3. **Group** schedule — among the employee's groups *on that date* that have a schedule, lowest priority wins
4. **Department** schedule — the employee's department *on that date*, else its nearest ancestor with one
5. **Organization** default

Department, groups and location are read from Organization's dated history per date, so a transfer mid-range
switches schedule (and timezone) on the transfer date. Each day's timezone is the location's (if set), else the
workspace's. Before the hire date and after the termination date the status is `not_employed`.

Day status: `working`, `rest` (cycle day without timetable), `holiday` (holiday on a working day; periods are
kept for overtime rules), `day_off` (temporary), `unscheduled` (no level has a schedule), `not_employed`.

Performance: everything for the range is loaded up front (≈ 8 queries + 3 per distinct shift), so the query
count does not grow with days or employees (tested).

## Data model (tenant DB)

| Table                  | Notes |
| ---------------------- | ----- |
| `schedule_assignments` | `target_type` (organization/department/group/employee), `target_id` (null for organization; polymorphic, no FK — rows of deleted targets are ignored), `shift_id` FK restrict, `anchor_date`, `effective_from`, `effective_to` (null = open); unique (target, from); periods of one target never overlap |
| `temporary_schedules`  | `employee_id` FK, `date_from`, `date_to` (≤ 366 days), `shift_id` (null = day off) FK restrict, `anchor_date`, `reason`; ranges of one employee never overlap |
| `holidays`             | `date`, `name`, `location_id` (null = everyone; cascade), `recurs_yearly`; one per date and scope (checked in PHP: SQLite unique treats NULLs as distinct) |

## Assigning (`ScheduleAssignmentService::assign`)

- **With an end date**, the new period overwrites that range: periods inside it are deleted, periods
  crossing an edge are trimmed, a period spanning it is split around it.
- **Without an end date**, it starts in a period → takes over that period's remainder (a planned gap after it
  stays); starts in a gap → runs until the next planned period.
- `update` changes shift, anchor or end (end must stay before the next period); target and start are fixed.
- Every change (assignments, temporary schedules, holidays) fires `ScheduleChanged(scopeType, scopeId, from,
  to)` after commit. **No listener exists yet**: Module 5 will use it to recalculate attendance.

## Other modules touched

- **Organization:** `AssignmentService::forEmployees()`, `MembershipService::forEmployees()` (range loading for
  many employees) and `DepartmentTree::ancestorChains()`. Additive only.
- **Timetable:** this module registers `UsageRegistry::whenShiftInUse()`, which activates the read-only lock
  built in Module 3.

## Endpoints

`schedule-assignments` (CRUD; filters target, shift, `active_on`) · `temporary-schedules` (list across
employees; search, `employee_id`, `active_on`, `kind`) · `employees/{id}/temporary-schedules` (list, create) · `temporary-schedules/{id}` (update, delete) · `employees/{id}/schedule?from&to` (≤ 62 days) ·
`me/schedule` · `roster?from&days&department_id&group_id&search` · `holidays` (CRUD, `?year=`). See
`docs/api/openapi.yaml` (v0.4.0).

Permissions: `schedules.view` (HR, Department Manager — employee-level rows, schedules and roster limited to
their people), `schedules.manage` (HR), `holidays.view` (everyone), `holidays.manage` (HR).

## Screens

- **Schedules:** tabs Organization / Departments / Groups / Employees with dated periods (current, planned,
  ended), "Assign shift" dialog with the right target picker, edit and delete. A **Temporary** tab lists every
  employee's temporary changes (search, type, active-on filters) and adds them with an employee picker.
- **Roster:** employees × 7 or 14 days, week navigation, department/group/search filters, sticky employee
  column, cells coloured by day status, times in each day's timezone.
- **Holidays:** per year, recurring marked, per-location scope.
- **Employee profile → Schedule tab:** month calendar with the deciding level per day, temporary changes
  (add/edit/remove).
- **My schedule:** the same calendar for the signed-in employee; list layout on phones.

## Tests

44 new Pest tests (287 total). Resolver: every precedence level, groups without a schedule falling through,
sub-department inheritance, mid-period change, transfer with location timezone change, rotation anchor,
hire/termination boundaries with an overnight last shift, holidays on working/rest days, location holidays,
recurring holidays, temporary day off and cover, unscheduled, flat query count. API: assignment splitting,
gap-filling, overwriting, validation, update/delete, lock-on-start through to timetables, manager scoping,
temporary schedules, holidays (duplicates, recurrence, leap day), employee/my schedule, roster with visibility,
tenant isolation (raw SQLite file check). The demo-seeder test now covers schedules and holidays.

## Known limitations / follow-ups

- `ScheduleChanged` has no consumer until Module 5.
- No per-day timetable override inside a temporary schedule (approved as a later addition if needed).
- Moving holidays (Eid, etc.) are entered each year.
- The roster uses current department/group membership for its filters (like the employee list); each day's
  schedule itself is resolved from history.
- Schedule rows whose department/group/employee was deleted show as "Deleted" and are ignored by the resolver.
