import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import type { LoginValues } from '@/lib/login-schema'
import { ApiError, centralApi } from '@/lib/api/client'
import type { Resource } from '@/lib/api/types'

/** Mirrors SuperAdminResource.php */
export interface SuperAdmin {
  id: number
  name: string
  email: string
  role: 'super-admin'
  last_login_at: string | null
}

export const adminSessionKey = ['central', 'session'] as const

export function useAdminSessionQuery() {
  return useQuery<SuperAdmin | null>({
    queryKey: adminSessionKey,
    queryFn: async ({ signal }) => {
      try {
        return (await centralApi.get<Resource<SuperAdmin>>('/auth/me', undefined, signal)).data
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

export function useAdmin(): SuperAdmin {
  const { data } = useAdminSessionQuery()

  if (!data) {
    throw new Error('useAdmin() used outside an authenticated route.')
  }

  return data
}

export function useAdminLogin() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: (values: LoginValues) => centralApi.post<Resource<SuperAdmin>>('/auth/login', values).then((r) => r.data),
    onSuccess: (admin) => {
      queryClient.setQueryData(adminSessionKey, admin)
      void navigate('/', { replace: true })
    },
  })
}

export function useAdminLogout() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: () => centralApi.post<undefined>('/auth/logout'),
    onSettled: () => {
      queryClient.clear()
      queryClient.setQueryData(adminSessionKey, null)
      void navigate('/login', { replace: true })
    },
  })
}
