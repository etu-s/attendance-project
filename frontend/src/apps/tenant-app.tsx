import { useMemo } from 'react'
import { Home, Loader2, ScrollText, Settings, Users } from 'lucide-react'
import { createBrowserRouter, Navigate, Outlet, RouterProvider } from 'react-router'
import { AppShell, type NavItem } from '@/components/app-shell'
import { FullPageMessage } from '@/components/states'
import { Button } from '@/components/ui/button'
import { AuditLogPage } from '@/features/audit-logs/audit-log-page'
import { HomePage } from '@/features/home/home-page'
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

function RequirePermission({ permission }: { permission: string }) {
  const can = useCan()

  return can(permission) ? (
    <Outlet />
  ) : (
    <FullPageMessage title="No access" description="Your role does not include this page. Ask a Tenant Admin if you need it." />
  )
}

function TenantLayout() {
  const { user, workspace } = useSession()
  const can = useCan()
  const logout = useLogout()

  const nav: NavItem[] = [
    { to: '/', label: 'Home', icon: Home, end: true },
    ...(can('users.view') ? [{ to: '/users', label: 'Users', icon: Users }] : []),
    ...(can('settings.view') ? [{ to: '/settings', label: 'Settings', icon: Settings }] : []),
    ...(can('audit-logs.view') ? [{ to: '/audit-log', label: 'Audit log', icon: ScrollText }] : []),
  ]

  return (
    <AppShell
      brand={workspace.name}
      subtitle="Attendance"
      nav={nav}
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
                    {
                      element: <RequirePermission permission="users.view" />,
                      children: [{ path: '/users', element: <UsersPage /> }],
                    },
                    {
                      element: <RequirePermission permission="settings.view" />,
                      children: [{ path: '/settings', element: <SettingsPage /> }],
                    },
                    {
                      element: <RequirePermission permission="audit-logs.view" />,
                      children: [{ path: '/audit-log', element: <AuditLogPage /> }],
                    },
                    {
                      path: '*',
                      element: <FullPageMessage title="Page not found" description="This page does not exist." />,
                    },
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
