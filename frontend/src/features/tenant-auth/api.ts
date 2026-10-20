import { tenantApi } from '@/lib/api/client'
import type { Resource } from '@/lib/api/types'
import type { LoginValues } from '@/lib/login-schema'
import type { User } from '@/features/users/types'

export interface Workspace {
  name: string
  timezone: string
  date_format: string
  time_format: string
  week_start: number
}

/** Mirrors AuthenticatedUserResource.php */
export interface Session {
  user: User
  permissions: string[]
  workspace: Workspace
}

export const sessionKey = ['tenant', 'session'] as const

export const tenantAuthApi = {
  me: (signal?: AbortSignal) => tenantApi.get<Resource<Session>>('/auth/me', undefined, signal).then((r) => r.data),
  login: (values: LoginValues) => tenantApi.post<Resource<Session>>('/auth/login', values).then((r) => r.data),
  logout: () => tenantApi.post<undefined>('/auth/logout'),
}
