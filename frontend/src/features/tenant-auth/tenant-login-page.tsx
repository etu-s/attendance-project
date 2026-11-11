import { Link, Navigate } from 'react-router'
import { LoginForm } from '@/components/login-form'
import { useLogin, useSessionQuery } from './hooks'

export function TenantLoginPage({ subdomain }: { subdomain: string }) {
  const session = useSessionQuery()
  const login = useLogin()

  if (session.data) {
    return <Navigate to="/" replace />
  }

  return (
    <LoginForm
      title="Sign in"
      description={`Sign in to the ${subdomain} workspace.`}
      onSubmit={(values) => login.mutateAsync(values)}
      footer={
        <Link to="/forgot-password" className="text-muted-foreground underline underline-offset-4">
          Forgot your password?
        </Link>
      }
    />
  )
}
