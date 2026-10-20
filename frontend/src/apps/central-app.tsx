import { Building2, Layers, Loader2 } from 'lucide-react'
import { createBrowserRouter, Navigate, Outlet, RouterProvider } from 'react-router'
import { AppShell } from '@/components/app-shell'
import { LoginForm } from '@/components/login-form'
import { FullPageMessage } from '@/components/states'
import { Button } from '@/components/ui/button'
import { useAdmin, useAdminLogin, useAdminLogout, useAdminSessionQuery } from '@/features/central-auth/api'
import { PlansPage } from '@/features/plans/plans-page'
import { TenantsPage } from '@/features/tenants/tenants-page'
import { errorMessage } from '@/lib/forms'

function Gate() {
  const session = useAdminSessionQuery()

  if (session.isPending) {
    return (
      <div className="flex min-h-svh items-center justify-center" role="status" aria-label="Loading">
        <Loader2 className="text-muted-foreground size-6 animate-spin" />
      </div>
    )
  }

  if (session.isError) {
    return (
      <FullPageMessage
        title="Cannot reach the server"
        description={errorMessage(session.error)}
        action={<Button onClick={() => session.refetch()}>Try again</Button>}
      />
    )
  }

  return <Outlet />
}

function LoginPage() {
  const session = useAdminSessionQuery()
  const login = useAdminLogin()

  if (session.data) {
    return <Navigate to="/" replace />
  }

  return (
    <LoginForm
      title="Platform admin"
      description="Sign in to manage tenants and plans."
      onSubmit={(values) => login.mutateAsync(values)}
    />
  )
}

function RequireAdmin() {
  const session = useAdminSessionQuery()

  return session.data ? <Outlet /> : <Navigate to="/login" replace />
}

function CentralLayout() {
  const admin = useAdmin()
  const logout = useAdminLogout()

  return (
    <AppShell
      brand="AMS Platform"
      subtitle="Super admin"
      nav={[
        { to: '/', label: 'Tenants', icon: Building2, end: true },
        { to: '/plans', label: 'Plans', icon: Layers },
      ]}
      user={admin}
      onLogout={() => logout.mutate()}
      loggingOut={logout.isPending}
    />
  )
}

const router = createBrowserRouter([
  {
    element: <Gate />,
    children: [
      { path: '/login', element: <LoginPage /> },
      {
        element: <RequireAdmin />,
        children: [
          {
            element: <CentralLayout />,
            children: [
              { index: true, element: <TenantsPage /> },
              { path: '/plans', element: <PlansPage /> },
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
])

export function CentralApp() {
  return <RouterProvider router={router} />
}
