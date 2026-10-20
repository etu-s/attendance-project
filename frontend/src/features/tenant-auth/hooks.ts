import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo } from 'react'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { ApiError } from '@/lib/api/client'
import type { DisplayPreferences } from '@/lib/format'
import { sessionKey, tenantAuthApi, type Session } from './api'

/**
 * The tenant session. `null` = signed out (401); errors such as 404 (unknown
 * workspace) or 403 (suspended) are surfaced to the gate in TenantApp.
 */
export function useSessionQuery() {
  return useQuery<Session | null>({
    queryKey: sessionKey,
    queryFn: async ({ signal }) => {
      try {
        return await tenantAuthApi.me(signal)
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
          return null
        }
        throw error
      }
    },
    staleTime: 5 * 60_000,
  })
}

/** The signed-in session; only call below the auth gate. */
export function useSession(): Session {
  const { data } = useSessionQuery()

  if (!data) {
    throw new Error('useSession() used outside an authenticated route.')
  }

  return data
}

/**
 * UI-only permission check (hide or disable actions). The API enforces the
 * same permissions; this never replaces backend authorization.
 */
export function useCan(): (permission: string) => boolean {
  const { permissions } = useSession()

  return useMemo(() => {
    const granted = new Set(permissions)

    return (permission: string) => granted.has(permission)
  }, [permissions])
}

export function useDisplayPreferences(): DisplayPreferences {
  const { workspace } = useSession()

  return useMemo(
    () => ({ timezone: workspace.timezone, dateFormat: workspace.date_format, timeFormat: workspace.time_format }),
    [workspace.timezone, workspace.date_format, workspace.time_format],
  )
}

export function useLogin() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: tenantAuthApi.login,
    onSuccess: (session) => {
      queryClient.setQueryData(sessionKey, session)
      void navigate('/', { replace: true })
    },
  })
}

export function useLogout() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: tenantAuthApi.logout,
    onSettled: () => {
      // Drop every cached tenant query so nothing from this session lingers.
      queryClient.clear()
      queryClient.setQueryData(sessionKey, null)
      void navigate('/login', { replace: true })
    },
    onError: () => toast.error('Signed out locally; the server session may still be active.'),
  })
}
