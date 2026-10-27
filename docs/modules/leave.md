# Module 6 — Leave Management

Status: **approved** (2026-10-27)

Leave types, requests with a single approval step, and balances kept as an **append-only ledger**. Approved
leave feeds attendance (`on_leave`, half days evaluated). Code: `app/Modules/Leave`, `frontend/src/features/leave`;
integration pieces in `app/Modules/Core` (contract, event) and `app/Modules/Attendance` (status, evaluator).

## Decisions (approved 2026-10-22)

1. Units: full and half days, stored as whole **half-days** (1.5 days = 3). Hourly leave later.
2. Earning per type: none, yearly grant, or monthly accrual. Joiners are prorated by the months remaining
   (hire month included), rounded down to half-days. The leave year starts in `leave.year_start_month` (January).
3. Year end: the balance carries forward up to the type's cap; the rest expires. Carried days do not expire.
   A negative balance carries forward in full.
4. Negative balance: per-type limit (default 0). "Unlimited" types (unpaid leave) keep no balance limit.
5. Approval: one step, by the employee's manager or HR, never one's own request or about oneself. Leave HR or a
   Tenant Admin enters for someone else is approved at once.
6. Attendance: a full day of leave is `on_leave`, even with punches (kept, minutes recorded for overtime). A half
   day excuses that half; the other half is evaluated from the day's midpoint (first half off: expected start moves
   to the midpoint; second half off: expected end does).
7. Cancelling: employees cancel pending leave and approved leave that has not started; only HR (`leave.manage`)
   cancels started or past approved leave. Every cancellation credits back what the request took.
8. A schedule or holiday change after approval recounts the request automatically; the difference is a `recount`
   ledger entry.
9. Attachments (medical certificates) are deferred to Hardening.

## Counting

`LeaveDayCounter` uses `ScheduleResolver`: only days with status `working` count. Holidays, rest days, temporary
days off and days outside employment cost nothing; rotating shifts count only their working days. `half_start`
makes the first day its second half, `half_end` makes the last day its first half (both on a single day is refused).
Requests are at most 366 days. Two active requests may share a date only as opposite halves.

## The ledger

`leave_ledger` is the only source of balances and is **append-only** (SQLite triggers abort UPDATE/DELETE).

| Kind | Sign | Written by |
| --- | --- | --- |
| `grant` | + | `leave:accrue`, start of the leave year (prorated for joiners) |
| `accrual` | + | `leave:accrue`, monthly (cumulative twelfths, so the year adds up exactly) |
| `carry_forward` | ± | `leave:accrue`, first run of a new leave year (capped; negative in full) |
| `expiry` | − | `leave:accrue`, the part above the cap |
| `request` | − | approval, per leave year the request touches |
| `cancellation` | + | cancelling approved leave (what it took, net of recounts) |
| `recount` | ± | schedule/holiday change after approval |
| `adjustment` | ± | HR, with a reason |

Grants, accruals, carry-forward and expiry have a `period_key` with a unique index: `leave:accrue` can run any
number of times. It is scheduled hourly so each workspace's new day is picked up in its own timezone.

Balance (per type and leave year) = sum of entries; available = balance − pending requests. A request is allowed
while available − requested ≥ −negative limit (re-checked at approval).

## Attendance integration

- Core contract `LeaveCalendar::forEmployees($ids, $from, $to)` → approved counted days (`LeaveDay`: portion, type).
  Leave binds `LeaveCalendarService`; Core binds an empty default, so Attendance never depends on Leave.
- Core event `LeaveChanged($employeeId, $from, $to)` after approve / cancel / recount; Attendance recalculates past days.
- Attendance (`DayEvaluator`): leave applies to working days only (a holiday inside leave stays a holiday);
  full → `on_leave`, scheduled 0, no late/early; half → flag `half_day_leave`, periods inside the leave half excused,
  expected start/end moved to the midpoint, scheduled minutes and minimum work halved. New columns
  `attendance_days.leave_name`, `leave_portion` (Attendance migration).
- Leave listens to `ScheduleChanged` (job `RecountLeave`) to recount pending and approved requests.

## Data model (tenant DB)

| Table | Notes |
| --- | --- |
| `leave_types` | name, code (NOCASE unique), colour, paid, accrual, `entitlement_halves`, `carry_forward_max_halves` (null = none), `negative_limit_halves`, unlimited, active |
| `leave_requests` | employee, type, start/end date, `half_start`, `half_end`, `days_halves`, reason, status (pending/approved/rejected/cancelled), requested/decided/cancelled by + at, decision note; soft deletes; indexes (employee, start), (status, start) |
| `leave_request_days` | counted days (date, portion, leave year) per request; unique (request, date); index (employee, date) — rebuilt on recount |
| `leave_ledger` | employee, type, leave year, kind, `amount_halves`, request, `period_key` (unique with employee/type/kind), reason, created by/at; **append-only** |

## Endpoints and permissions

`leave-types` (CRUD; `?all=1` for inactive) · `leave-requests` (list: status/type/employee/department/from/to/
search/`mine`; create) · `leave-requests/preview` · `leave-requests/{id}` (+ `approve`, `reject`, `cancel`) ·
`leave-calendar?from&to` (≤ 62 days) · `employees/{id}/leave-balances?year=` · `employees/{id}/leave-ledger?year=` ·
`me/leave-balances` · `leave-adjustments`. See `docs/api/openapi.yaml` (v0.6.0). Responses show amounts in days.

| Permission | Default roles |
| --- | --- |
| `leave.request` | HR, Department Manager, Employee (for people one can see: employees themselves) |
| `leave.view` (team requests, calendar, balances, ledger) | HR, Department Manager (their people) |
| `leave.approve` | HR, Department Manager |
| `leave.manage` (types, adjustments, cancelling started leave) | HR |

## Screens

**My leave** (balance cards, request dialog with a live count and balance-after, my requests with cancel; phones) ·
**Leave requests** (pending/approved/rejected/cancelled, approve/reject with a note, cancel, "Record leave" for HR) ·
**Leave calendar** (month agenda of who is off; pending dashed) · **Leave types** · **Employee → Leave tab**
(per leave year: balances, ledger, adjust, request on their behalf) · attendance shows **On leave** (leave colour)
with the leave name; Today has an "On leave" tile.

## Commands

```bash
php artisan leave:accrue [--tenant=<id>]   # grants, accruals, carry-forward, expiry due by today (scheduled hourly)
```

The local demo workspace gets annual (14 days, 5 carried), sick (10) and unpaid (unlimited) leave, this year's grants
and requests in every status (`DemoLeaveSeeder`).

## Tests

27 new Pest tests (379 total). Requests: counting (weekends, holidays), half days, overlaps and opposite halves,
balance down to the negative limit, unlimited types, refusals, manager approval with the ledger debit and event,
never deciding one's own leave, HR auto-approval, cancelling (pending, future, started → HR only, credits),
recount when a holiday is added, visibility (`mine`, manager scope, calendar), tenant isolation (raw SQLite file).
Accrual: yearly grant + joiner proration, monthly cumulative accrual, year-end carry/expiry incl. negative
balances, leave year start month, append-only ledger, the command. Admin: types in days (validation, unlimited
normalisation, delete rule, inactive), adjustments, balances, ledger, own balances. Attendance: backdated sick leave
turns absent into on-leave, full day with punches, half days (excused half, late from the midpoint, absent other
half), holiday inside leave, cancellation restores attendance. The demo-seeder test covers leave.

## Known limitations / follow-ups

- Attachments (medical certificates) — Hardening.
- Hourly leave is not supported (half days only).
- Leave requested before a joiner's next `leave:accrue` run sees no balance yet (the grant appears within the hour).
- Year-end carry-forward and expiry are fixed once written; ledger changes in an already closed leave year
  (e.g. a late cancellation) are not re-carried — HR adjusts.
- Approved future leave is not cancelled automatically when an employee is terminated.
- No email notifications yet (in-app status only).
