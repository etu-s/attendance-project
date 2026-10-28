# Module 7 — Overtime Management

Status: **approved** (2026-10-28)

Overtime is calculated from attendance days, then approved by a manager or HR after the fact. HR can also record
overtime that no punch captured. Code: `app/Modules/Overtime`, `frontend/src/features/overtime`; integration pieces
in `app/Modules/Attendance` (event, read service, simulator).

## Decisions (approved 2026-10-27)

1. Working days: overtime is the time after the scheduled end, capped by the day's net surplus
   (worked − scheduled), so a late start is made up first. Time before the start counts only with
   `overtime.count_early_arrival` (off by default).
2. Rest days, days off and unscheduled days: all worked time at the rest-day rate. Holidays: all worked time at the
   holiday rate.
3. One rule set per workspace, in Settings (per-department policies later).
4. Defaults: at least 30 minutes a day, rounded down to 15, no daily cap (0).
5. Rates are integer percentages (working day 150, rest day 200, holiday 200). The module reports **weighted
   minutes** (minutes × rate ÷ 100), not money.
6. Approval happens after the fact, in one step, by the employee's manager or HR, never on one's own overtime. The
   approver may approve fewer minutes. Approvers can approve many records at once.
7. When a decided record's calculation changes, it goes back to **pending** with `changed_after_decision`; the
   audit log keeps the earlier decision.
8. HR records manual overtime with a reason. HR or a Tenant Admin entering it for someone else approves it at once.
9. No overtime is calculated on a full day of approved leave (HR records it manually if needed). A half day of leave
   halves the scheduled time, so the surplus is measured against the half day.
10. Payroll period locking is deferred (Reports / Hardening).

## Calculation

`OvertimeCalculator::evaluate(AttendanceDay, OvertimeSettings)`:

| Attendance status | Overtime |
| --- | --- |
| in progress, not employed, on leave | none |
| rest, day off, unscheduled | `rest_day_minutes` = worked minutes |
| holiday | `holiday_minutes` = worked minutes |
| any other (present, late, early leave, incomplete, …) | `after_minutes` (+ `before_minutes`) per period, capped by `max(0, worked − scheduled)` |

Then, on the day's total: below the minimum → nothing; rounded **down** to the rounding step; capped by the daily
cap. What rounding and the cap remove is trimmed from early arrival first, then after-shift, rest-day and holiday
minutes. Overnight shifts belong to their work date (the shift's start), like attendance.

Rates are copied onto the record when it is calculated, so later rate changes affect only days recalculated after
the change ("Recalculate range" on the summary page, or `POST /overtime/recalculate`).

## How it stays in sync

- Attendance fires `AttendanceRecalculated($employeeId, $dates)` after each write (new event in
  `app/Modules/Attendance/Events`). Overtime listens **synchronously**, so the overtime is written by the same
  queued writer job (`ProcessPunches` / `RecalculateAttendance`, one writer per tenant) and is never stale.
- Overtime reads days through `AttendanceQuery::withPeriods()`; it never queries attendance tables directly.
- Recalculation is idempotent: unchanged records are not written. A record that drops to zero is deleted when still
  undecided, and reopened at zero when it was decided (approving a zero record is refused with `overtime_empty`).
- `RecalculateOvertime` (queued, same writer lock) recalculates a range with the current settings.

## Data model (tenant DB)

| Table | Notes |
| --- | --- |
| `overtime_records` | employee, `work_date`, source (calculated/manual), `before/after/rest_day/holiday_minutes`, `calculated_minutes`, `rate_workday/rest_day/holiday`, `weighted_minutes`, status (pending/approved/rejected), `approved_minutes`, `approved_weighted_minutes`, decided by/at, decision note, `changed_after_decision`, reason, created by; soft deletes; unique (employee, work_date, source); indexes (status, work_date), (work_date) |

One calculated and one manual record at most per employee and day. Approved weighted minutes =
weighted × approved ÷ calculated (rounded down). Records are audit-logged.

## Settings (`overtime.*`)

| Key | Default |
| --- | --- |
| `overtime.min_minutes` | 30 |
| `overtime.rounding_minutes` | 15 (round down; 0 = exact) |
| `overtime.daily_cap_minutes` | 0 (no cap) |
| `overtime.count_early_arrival` | false |
| `overtime.rate_workday` | 150 |
| `overtime.rate_rest_day` | 200 |
| `overtime.rate_holiday` | 200 |

## Endpoints and permissions

`overtime` (list: status/source/employee/department/from/to/`changed`/search) · `overtime/summary` (per employee:
approved, weighted, split by kind, pending) · `overtime/{id}/approve` (optional `minutes`, `note`) ·
`overtime/{id}/reject` · `overtime/approve-batch` (≤ 200 ids; skips what the approver may not decide) ·
`overtime/recalculate` (≤ 92 days, 202) · `overtime-entries` (create; update / delete while pending) ·
`employees/{id}/overtime?from&to` · `me/overtime?from&to` (≤ 62 days). See `docs/api/openapi.yaml` (v0.7.0).

Business-rule codes: `overtime_own` (403), `overtime_decided`, `overtime_empty`, `overtime_calculated` (409).

| Permission | Default roles |
| --- | --- |
| `overtime.view` (inbox, summary, employee tab) | HR, Department Manager (their people) |
| `overtime.approve` | HR, Department Manager |
| `overtime.manage` (manual entries, recalculation) | HR |

Everyone signed in and linked to an employee sees their own overtime (`me/overtime`).

## Screens

**Overtime** (pending / approved / rejected; date filters and search; approve with fewer minutes or reject with a
note; select and approve in bulk; "Record overtime"; edit/delete pending manual entries; "Changed after a decision"
marker) · **Overtime summary** (this month by default: approved, weighted, working / rest / holiday split, pending;
"Recalculate range" for HR) · **My overtime** (month view, phones) · **Employee → Overtime tab** (month view,
record overtime) · the attendance day drawer shows the day's overtime · **Settings → Overtime**.

## Commands

```bash
php artisan punches:simulate --tenant=acme --overtime-rate=0.25   # share of people staying 35–120 min late (default 0.1)
```

The local demo workspace gets overtime from the simulated punches (`DemoSeeder`).

## Tests

16 new Pest tests (395 total). Calculation: after-shift time rounded down at the working-day rate, a late start made
up first, early arrival only with the setting, daily cap, rest days and holidays at their rates, overnight shifts on
their work date, half-day leave (halved expected time) and full leave days (none), reopening a decided record when
its calculation changes and dropping undecided ones. API: visibility and own overtime, approving fewer minutes with
proportional weight and rejecting, never deciding one's own, batch approval with skips, manual entries (HR
auto-approval), editing and deleting pending manual entries, recalculation with new rates, tenant isolation (raw
SQLite file).

## Known limitations / follow-ups

- No payroll period locking: a recalculation can reopen overtime that was already paid (flagged with
  `changed_after_decision`). Planned for Reports / Hardening.
- One rule set per workspace; per-department or per-employee policies are not supported.
- No money amounts (weighted minutes only); pay rates belong to payroll.
- No pre-approval (requesting overtime before working it).
- Work on a full day of leave is not counted automatically; HR records it manually.
- The summary splits approved minutes by kind in proportion when fewer minutes were approved.
- No email notifications yet (in-app status only).
