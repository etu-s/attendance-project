import { useMemo } from 'react'
import {
  Building,
  Contact,
  Home,
  Loader2,
  MapPin,
  Network,
  ScrollText,
  Settings,
  UserRound,
  Users,
  UsersRound,
} from 'lucide-react'
import { createBrowserRouter, Navigate, Outlet, RouterProvider, type RouteObject } from 'react-router'
import { AppShell, type NavItem } from '@/components/app-shell'
import { FullPageMessage } from '@/components/states'
import { Button } from '@/components/ui/button'
import { AuditLogPage } from '@/features/audit-logs/audit-log-page'
import { EmployeeDetailPage } from '@/features/employees/employee-detail-page'
import { EmployeesPage } from '@/features/employees/employees-page'
import { MyProfilePage } from '@/features/employees/my-profile-page'
import { HomePage } from '@/features/home/home-page'
import { DepartmentsPage } from '@/features/organization/departments-page'
import { GroupsPage } from '@/features/organization/groups-page'
import { LocationsPage } from '@/features/organization/locations-page'
import { OrganizationPage } from '@/features/organization/organization-page'
import { SettingsPage } from '@/features/settings/settings-page'
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

  const items: (NavItem & { visible: boolean })[] = [
    { to: '/', label: 'Home', icon: Home, end: true, visible: true },
    { to: '/me', label: 'My profile', icon: UserRound, visible: true },
    { to: '/employees', label: 'Employees', icon: Contact, visible: can('employees.view') || can('employees.view-all') },
    { to: '/departments', label: 'Departments', icon: Network, visible: can('departments.view') },
    { to: '/groups', label: 'Groups', icon: UsersRound, visible: can('groups.view') },
    { to: '/locations', label: 'Locations', icon: MapPin, visible: can('locations.view') },
    { to: '/organization', label: 'Organization', icon: Building, visible: can('organization.view') },
    { to: '/users', label: 'Users', icon: Users, visible: can('users.view') },
    { to: '/settings', label: 'Settings', icon: Settings, visible: can('settings.view') },
    { to: '/audit-log', label: 'Audit log', icon: ScrollText, visible: can('audit-logs.view') },
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
