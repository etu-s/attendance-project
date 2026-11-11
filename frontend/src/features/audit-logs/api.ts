import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { centralApi, tenantApi } from '@/lib/api/client'
import type { ListParams, Paginated } from '@/lib/api/types'

/** Mirrors App\Modules\Core\Enums\AuditAction */
export const auditActions = ['created', 'updated', 'deleted', 'restored', 'role_changed'] as const
export type AuditAction = (typeof auditActions)[number]

/** The workspace audit log, or the central one (super admin console). */
export type AuditScope = 'tenant' | 'central'

/** Mirrors AuditLogResource.php */
export interface AuditLog {
  id: number
  action: AuditAction
  actor: { type: 'user' | 'super_admin' | 'system'; id: number | null; name: string | null }
  subject: { type: string; id: string }
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  ip_address: string | null
  created_at: string
}

export function useAuditLogs(params: ListParams, scope: AuditScope = 'tenant') {
  const api = scope === 'central' ? centralApi : tenantApi

  return useQuery({
    queryKey: [scope, 'audit-logs', params],
    queryFn: ({ signal }) => api.get<Paginated<AuditLog>>('/audit-logs', params, signal),
    placeholderData: keepPreviousData,
  })
}
