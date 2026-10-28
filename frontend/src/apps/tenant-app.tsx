import { useMemo } from 'react'
import {
  Activity,
  Building,
  CalendarCheck,
  CalendarClock,
  CalendarDays,
  CalendarHeart,
  CalendarRange,
  CalendarSync,
  ClipboardList,
  Clock,
  LayoutGrid,
  Contact,
  Fingerprint,
  Home,
  Hourglass,
  Inbox,
  Loader2,
  MapPin,
  Network,
  PencilLine,
  Plane,
  RadioTower,
  ScanSearch,
  ScrollText,
  Sigma,
  Tags,
  Timer,
  Settings,
  UserRound,
  Users,
  UsersRound,
} from 'lucide-react'
import { createBrowserRouter, Navigate, Outlet, RouterProvider, type RouteObject } from 'react-router'
import { AppShell, type NavItem } from '@/components/app-shell'
import { FullPageMessage } from '@/components/states'
import { Button } from '@/components/ui/button'
import { AttendancePage } from '@/features/attendance/attendance-page'
import { LeaveCalendarPage } from '@/features/leave/leave-calendar-page'
import { LeaveRequestsPage } from '@/features/leave/leave-requests-page'
import { LeaveTypesPage } from '@/features/leave/leave-types-page'
import { MyLeavePage } from '@/features/leave/my-leave-page'
import { MyOvertimePage } from '@/features/overtime/my-overtime-page'
import { OvertimePage } from '@/features/overtime/overtime-page'
import { OvertimeSummaryPage } from '@/features/overtime/overtime-summary-page'
import { CorrectionsPage } from '@/features/attendance/corrections-page'
import { MyAttendancePage } from '@/features/attendance/my-attendance-page'
import { OrphanPunchesPage } from '@/features/attendance/orphan-punches-page'
import { PunchSourcesPage } from '@/features/attendance/punch-sources-page'
import { RawPunchesPage } from '@/features/attendance/raw-punches-page'
import { TodayPage } from '@/features/attendance/today-page'
import { AuditLogPage } from '@/features/audit-logs/audit-log-page'
import { EmployeeDetailPage } from '@/features/employees/employee-detail-page'
import { EmployeesPage } from '@/features/employees/employees-page'
import { MyProfilePage } from '@/features/employees/my-profile-page'
import { HomePage } from '@/features/home/home-page'
import { DepartmentsPage } from '@/features/organization/departments-page'
import { GroupsPage } from '@/features/organization/groups-page'
import { LocationsPage } from '@/features/organization/locations-page'
import { OrganizationPage } from '@/features/organization/organization-page'
import { HolidaysPage } from '@/features/scheduling/holidays-page'
import { MySchedulePage } from '@/features/scheduling/my-schedule-page'
import { RosterPage } from '@/features/scheduling/roster-page'
import { SchedulesPage } from '@/features/scheduling/schedules-page'
import { SettingsPage } from '@/features/settings/settings-page'
import { ShiftDetailPage } from '@/features/timetables/shift-detail-page'
import { ShiftsPage } from '@/features/timetables/shifts-page'
import { TimetablesPage } from '@/features/timetables/timetables-page'
import { useCan, useLogout, useSession, useSessionQuery } from '@/features/tenant-auth/hooks'
import { TenantLoginPage } from '@/features/tenant-auth/tenant-login-page'
import { UsersPage } from '@/features/users/users-page'
import { ApiError } from '@/lib/api/client'
import { errorMessage } from '@/lib/forms'

function Loading() {
  return (
    <div className="flex min-h-svh items-center justify-center" role="status" aria-label="Loading">
      <Loader2 className="text-muted-foreground size-6 animate-spin" />
    </div>
  )
}

/**
 * Resolves the session once for the whole tenant app and turns
 * workspace-level API answers into full-page states.
 */
function SessionGate() {
  const session = useSessionQuery()

  if (session.isPending) {
    return <Loading />
  }

  if (session.isError) {
    const status = session.error instanceof ApiError ? session.error.status : 0

    if (status === 404) {
      return (
        <FullPageMessage
          title="Workspace not found"
          description="Check the address. This workspace does not exist or was removed."
        />
      )
    }
    if (status === 403 || status === 503) {
      return (
        <FullPageMessage
          title={status === 403 ? 'Workspace unavailable' : 'Almost ready'}
          description={errorMessage(session.error)}
          action={<Button onClick={() => session.refetch()}>Try again</Button>}
        />
      )
    }

    return (
      <FullPageMessage
        title="Something went wrong"
        description={errorMessage(session.error)}
        action={<Button onClick={() => session.refetch()}>Try again</Button>}
      />
    )
  }

  return <Outlet />
}

function RequireSession() {
  const session = useSessionQuery()

  return session.data ? <Outlet /> : <Navigate to="/login" replace />
}

/** Page-level gate; passes when the user holds any of the permissions. The API enforces the same. */
function RequirePermission({ permission }: { permission: string | string[] }) {
  const can = useCan()
  const allowed = (Array.isArray(permission) ? permission : [permission]).some((p) => can(p))

  return allowed ? (
    <Outlet />
  ) : (
    <FullPageMessage title="No access" description="Your role does not include this page. Ask a Tenant Admin if you need it." />
  )
}

function guarded(permission: string | string[], children: RouteObject[]): RouteObject {
  return { element: <RequirePermission permission={permission} />, children }
}

function TenantLayout() {
  const { user, workspace } = useSession()
  const can = useCan()
  const logout = useLogout()

  const corrections = can('corrections.request') || can('corrections.approve')
  const items: (NavItem & { visible: boolean })[] = [
    { to: '/', label: 'Home', icon: Home, end: true, visible: true },
    { to: '/me', label: 'My profile', icon: UserRound, visible: true },
    { to: '/my-attendance', label: 'My attendance', icon: CalendarCheck, visible: true },
    { to: '/my-schedule', label: 'My schedule', icon: CalendarClock, visible: true },
    { to: '/my-leave', label: 'My leave', icon: Plane, visible: can('leave.request') },
    { to: '/my-overtime', label: 'My overtime', icon: Timer, visible: true },
    { to: '/today', label: 'Today', icon: Activity, section: 'Attendance', visible: can('attendance.view') },
    { to: '/attendance', label: 'Attendance', icon: ClipboardList, section: 'Attendance', visible: can('attendance.view') },
    { to: '/corrections', label: 'Corrections', icon: PencilLine, section: 'Attendance', visible: corrections },
    { to: '/punch-log', label: 'Punch log', icon: Fingerprint, section: 'Attendance', visible: can('punches.view') },
    {
      to: '/unmatched-punches',
      label: 'Unmatched punches',
      icon: ScanSearch,
      section: 'Attendance',
      visible: can('punches.view'),
    },
    {
      to: '/punch-sources',
      label: 'Punch sources',
      icon: RadioTower,
      section: 'Attendance',
      visible: can('punch-sources.manage'),
    },
    {
      to: '/leave-requests',
      label: 'Leave requests',
      icon: Inbox,
      section: 'Leave',
      visible: can('leave.view') || can('leave.approve'),
    },
    { to: '/leave-calendar', label: 'Leave calendar', icon: CalendarRange, section: 'Leave', visible: can('leave.view') },
    { to: '/leave-types', label: 'Leave types', icon: Tags, section: 'Leave', visible: can('leave.manage') },
    { to: '/overtime', label: 'Overtime', icon: Hourglass, section: 'Overtime', visible: can('overtime.view') },
    { to: '/overtime-summary', label: 'Overtime summary', icon: Sigma, section: 'Overtime', visible: can('overtime.view') },
    { to: '/schedules', label: 'Schedules', icon: CalendarDays, section: 'Scheduling', visible: can('schedules.view') },
    { to: '/roster', label: 'Roster', icon: LayoutGrid, section: 'Scheduling', visible: can('schedules.view') },
    { to: '/holidays', label: 'Holidays', icon: CalendarHeart, section: 'Scheduling', visible: can('holidays.view') },
    { to: '/timetables', label: 'Timetables', icon: Clock, section: 'Scheduling', visible: can('timetables.view') },
    { to: '/shifts', label: 'Shifts', icon: CalendarSync, section: 'Scheduling', visible: can('shifts.view') },
    {
      to: '/employees',
      label: 'Employees',
      icon: Contact,
      section: 'Organization',
      visible: can('employees.view') || can('employees.view-all'),
    },
    { to: '/departments', label: 'Departments', icon: Network, section: 'Organization', visible: can('departments.view') },
    { to: '/groups', label: 'Groups', icon: UsersRound, section: 'Organization', visible: can('groups.view') },
    { to: '/locations', label: 'Locations', icon: MapPin, section: 'Organization', visible: can('locations.view') },
    { to: '/organization', label: 'Organization', icon: Building, section: 'Organization', visible: can('organization.view') },
    { to: '/users', label: 'Users', icon: Users, section: 'Administration', visible: can('users.view') },
    { to: '/settings', label: 'Settings', icon: Settings, section: 'Administration', visible: can('settings.view') },
    { to: '/audit-log', label: 'Audit log', icon: ScrollText, section: 'Administration', visible: can('audit-logs.view') },
  ]

  return (
    <AppShell
      brand={workspace.name}
      subtitle="Attendance"
      nav={items.filter((item) => item.visible)}
      user={user}
      onLogout={() => logout.mutate()}
      loggingOut={logout.isPending}
    />
  )
}

export function TenantApp({ subdomain }: { subdomain: string }) {
  const router = useMemo(
    () =>
      createBrowserRouter([
        {
          element: <SessionGate />,
          children: [
            { path: '/login', element: <TenantLoginPage subdomain={subdomain} /> },
            {
              element: <RequireSession />,
              children: [
                {
                  element: <TenantLayout />,
                  children: [
                    { index: true, element: <HomePage /> },
                    { path: '/me', element: <MyProfilePage /> },
                    { path: '/my-schedule', element: <MySchedulePage /> },
                    { path: '/my-attendance', element: <MyAttendancePage /> },
                    guarded('attendance.view', [
                      { path: '/today', element: <TodayPage /> },
                      { path: '/attendance', element: <AttendancePage /> },
                    ]),
                    guarded(
                      ['corrections.request', 'corrections.approve'],
                      [{ path: '/corrections', element: <CorrectionsPage /> }],
                    ),
                    guarded('punches.view', [
                      { path: '/punch-log', element: <RawPunchesPage /> },
                      { path: '/unmatched-punches', element: <OrphanPunchesPage /> },
                    ]),
                    guarded('punch-sources.manage', [{ path: '/punch-sources', element: <PunchSourcesPage /> }]),
                    guarded('leave.request', [{ path: '/my-leave', element: <MyLeavePage /> }]),
                    guarded(['leave.view', 'leave.approve'], [{ path: '/leave-requests', element: <LeaveRequestsPage /> }]),
                    guarded('leave.view', [{ path: '/leave-calendar', element: <LeaveCalendarPage /> }]),
                    guarded('leave.manage', [{ path: '/leave-types', element: <LeaveTypesPage /> }]),
                    { path: '/my-overtime', element: <MyOvertimePage /> },
                    guarded('overtime.view', [
                      { path: '/overtime', element: <OvertimePage /> },
                      { path: '/overtime-summary', element: <OvertimeSummaryPage /> },
                    ]),
                    guarded('schedules.view', [
                      { path: '/schedules', element: <SchedulesPage /> },
                      { path: '/roster', element: <RosterPage /> },
                    ]),
                    guarded('holidays.view', [{ path: '/holidays', element: <HolidaysPage /> }]),
                    guarded(
                      ['employees.view', 'employees.view-all'],
                      [
                        { path: '/employees', element: <EmployeesPage /> },
                        { path: '/employees/:id', element: <EmployeeDetailPage /> },
                      ],
                    ),
                    guarded('departments.view', [{ path: '/departments', element: <DepartmentsPage /> }]),
                    guarded('groups.view', [{ path: '/groups', element: <GroupsPage /> }]),
                    guarded('locations.view', [{ path: '/locations', element: <LocationsPage /> }]),
                    guarded('organization.view', [{ path: '/organization', element: <OrganizationPage /> }]),
                    guarded('timetables.view', [{ path: '/timetables', element: <TimetablesPage /> }]),
                    guarded('shifts.view', [
                      { path: '/shifts', element: <ShiftsPage /> },
                      { path: '/shifts/:id', element: <ShiftDetailPage /> },
                    ]),
                    guarded('users.view', [{ path: '/users', element: <UsersPage /> }]),
                    guarded('settings.view', [{ path: '/settings', element: <SettingsPage /> }]),
                    guarded('audit-logs.view', [{ path: '/audit-log', element: <AuditLogPage /> }]),
                    { path: '*', element: <FullPageMessage title="Page not found" description="This page does not exist." /> },
                  ],
                },
              ],
            },
          ],
        },
      ]),
    [subdomain],
  )

  return <RouterProvider router={router} />
}
