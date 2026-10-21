# Module 2 — Organization & Employees

Status: **approved** (2026-10-21)

The structure everything else hangs off: organization profile, locations, a department tree,
cross-department groups, and employees with **effective-dated** department/location/group history.
Code: `app/Modules/Organization`, `frontend/src/features/{organization,employees}`.

## Decisions (approved 2026-10-21)

1. Department, location and group membership are **dated** (`effective_from`/`effective_to`, inclusive,
   null = open-ended), so past attendance keeps the structure it actually had.
2. Departments nest; a manager of a department also sees its sub-departments.
3. `employee_code` is the punch identifier for now (multi-identifier table comes in Module 5).
4. Locations carry an optional timezone that overrides the workspace timezone.
5. Employee ↔ login is optional and one-to-one (`employees.user_id` unique); HR can create the login
   together with the employee.
6. Group `priority` is a unique integer; the lowest number wins when an employee is in several groups.
7. The plan's `max_employees` counts active + inactive employees, not terminated ones.
8. Minimal personal data: no date of birth, national ID or gender.
9. CSV import is part of this module (queued, row-level error report).

## Data model (tenant DB)

| Table                  | Notes                                                                                 |
| ---------------------- | ------------------------------------------------------------------------------------- |
| `organization_profile` | single row; legal/display name, registration no., address, phone, email                |
| `locations`            | `code` NOCASE unique, nullable `timezone`, `is_active`                                  |
| `departments`          | `parent_id` self-FK (restrict), `code` NOCASE unique, `is_active`                      |
| `department_managers`  | (department_id, user_id) PK; cascade on either side                                    |
| `groups`               | `code` NOCASE unique, **`priority` unique**, `is_active`                              |
| `group_managers`       | (group_id, user_id) PK                                                                 |
| `employees`            | `employee_code` NOCASE unique (incl. soft-deleted), `email` NOCASE unique, `employment_type`, `status`, `hire_date`/`termination_date` (`Y-m-d`), `user_id` unique nullable FK (null on delete), soft deletes |
| `employee_assignments` | employee, department, nullable location, `effective_from`/`to`; unique (employee, from); FKs restrict |
| `group_memberships`    | employee, group, `effective_from`/`to`; unique (employee, group, from)                 |
| `employee_imports`     | status, counts, row errors (JSON); raw CSV kept only until processed                   |

Dates are stored as `Y-m-d` text through the `Core\Casts\LocalDate` cast (Laravel's `date` cast would
store `Y-m-d 00:00:00`). "Today" is always the tenant's calendar day (`Core\Support\TenantClock`).

## Services other modules use

- `AssignmentService::on($employee, $date)`: department + location on a date.
- `MembershipService::on($employee, $date)`: groups on a date, **in precedence order** (lowest priority first).
- `EmployeeVisibility::apply($query, $user)` / `canSee()`: the single rule for who may see which
  employees. Attendance, leave and reports must scope through it.
- `Core\Contracts\TenantPlanLimits` (implemented by Central): plan limits readable from tenant context.
- `Identity\Events\UserDeleted`: Organization unlinks the employee and removes manager rows.

## Business rules

- **History invariant:** an employee's periods never overlap. A transfer on date D ends the covering
  period on D−1 and starts the new one on D, *keeping* the old period's end, so backdated transfers slot
  in without rewriting later history. A transfer on a period's own start day is a correction of that period.
- Transfers must fall within employment (not before hire, not after termination) and must change something.
- **Termination** closes assignments and memberships on the termination date (inclusive), deactivates the
  linked login by default (still subject to the last-admin rule), and is refused when history already
  continues after that date. Nobody can terminate their own record. `terminated` is reachable only this way.
- **Rehire** starts a new period after the gap; department/location default to the last ones held.
- **Hire-date correction** moves the start of the first assignment (and of earlier memberships).
- **Plan limit** checked inside the write transaction (SQLite `IMMEDIATE`), so two concurrent creates cannot
  both take the last seat; applies to create, import and rehire.
- **Deletion guards:** locations, departments and groups that appear in any history cannot be deleted
  (deactivate instead); departments with sub-departments cannot be deleted; department moves that would
  create a cycle are refused.
- Employee **delete** (Tenant Admin only) is for mistaken records: soft delete, code stays reserved, login freed.
- Every tenant gets an organization profile and, if it has none, a `General` department (idempotent,
  `tenants:seed`).

## Permissions

| Permission | Tenant Admin | HR Manager | Department Manager | Employee |
| --- | :-: | :-: | :-: | :-: |
| organization.view / locations.view / departments.view / groups.view | ✓ | ✓ | ✓ | |
| organization.update | ✓ | | | |
| locations.manage / departments.manage / groups.manage | ✓ | ✓ | | |
| employees.view-all | ✓ | ✓ | | |
| employees.view (scoped by EmployeeVisibility) | ✓ | ✓ | ✓ | |
| employees.create / update / terminate / import | ✓ | ✓ | | |
| employees.delete | ✓ | | | |

Everyone can read their own record at `GET /me/employee`. A Department Manager sees employees *currently*
in a department they manage (or below) or a group they manage, plus themselves; filters cannot widen that.

## Endpoints

All under `/api/v1` on the tenant host; see `docs/api/openapi.yaml` (v0.2.0) for request/response shapes.

`organization` (GET/PUT) · `locations` (CRUD) · `departments` (tree GET, CRUD, `PUT {id}/managers`) ·
`groups` (CRUD, `PUT {id}/managers`) · `employees` (list/filter/search, CRUD) ·
`employees/{id}/history|transfer|terminate|reinstate|groups|groups/{group}/end|user` ·
`employees/import` + `employee-imports[/{id}]` · `me/employee`.

## CSV import

Header row, any column order, case-insensitive. Required: `employee_code, first_name, last_name, hire_date
(Y-m-d), department_code`. Optional: `email, phone, job_title, employment_type, location_code, group_codes`
(`;`-separated). Max 2 MB / 5,000 rows; UTF-8 BOM handled. Each row is validated with the create-form
rules and created in its own short transaction; failures (bad data, duplicates inside the file or in the
DB, unknown codes, plan limit) are reported by line number. The job carries only the import id and runs in
tenant context (stancl queue bootstrapper).

## Screens

Employees (filters: department incl. sub-departments, location, group, status; search across name/code/email;
CSV import wizard) → employee profile (Details / History / Groups tabs; Edit, Transfer, Login,
Terminate/Rehire, Delete) · Departments (indented tree, headcount, managers) · Groups (priority, member count,
managers) · Locations · Organization profile · **My profile** (self-service, mobile-friendly).

## Tests

63 new Pest tests (189 total, 670 assertions): dated history (backdated transfers, corrections,
termination cut-off, overlap refusal, priority order), every endpoint, visibility scoping (sub-departments,
groups, moved-out employees, filter widening, plain employees), plan limit (create, import), import row
errors, cross-tenant isolation of every new table (raw SQLite file check), N+1 check (query count flat
from 2 to 22 employees), `LocalDate` unit tests incl. a DST boundary.

## Known limitations / follow-ups

- No undo for a transfer or termination: correct with another dated change. Editing or deleting
  individual history rows is not exposed.
- `employees.view` for Department Managers is "as of today"; viewing past members of a department
  belongs with attendance reports (Module 8).
- Group membership has no bulk add yet (add from each employee's profile, or via CSV import).
- The CSV import cannot update existing employees (create only).
- Devices / multiple punch identifiers per employee are Module 5.
