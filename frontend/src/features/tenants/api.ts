import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { centralApi } from '@/lib/api/client'
import type { ListParams, Paginated, Resource } from '@/lib/api/types'

/** Mirrors App\Modules\Central\Enums\TenantStatus */
export type TenantStatus = 'provisioning' | 'active' | 'suspended'

/** Mirrors TenantResource.php */
export interface Tenant {
  id: string
  name: string
  subdomain: string
  status: TenantStatus
  plan: { id: number; name: string }
  created_at: string
  updated_at: string
}

export interface CreateTenantPayload {
  name: string
  subdomain: string
  plan_id: number
  timezone: string
  admin_name: string
  admin_email: string
  admin_password: string
  admin_password_confirmation: string
}

const tenantsKey = ['central', 'tenants'] as const

export function useTenants(params: ListParams) {
  return useQuery({
    queryKey: [...tenantsKey, params],
    queryFn: ({ signal }) => centralApi.get<Paginated<Tenant>>('/tenants', params, signal),
    placeholderData: keepPreviousData,
  })
}

function useInvalidateTenants() {
  const queryClient = useQueryClient()

  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: tenantsKey }),
      // Plan tenant counts change when tenants move between plans.
      queryClient.invalidateQueries({ queryKey: ['central', 'plans'] }),
    ])
}

export function useCreateTenant() {
  const invalidate = useInvalidateTenants()

  return useMutation({
    mutationFn: (payload: CreateTenantPayload) => centralApi.post<Resource<Tenant>>('/tenants', payload).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useUpdateTenant() {
  const invalidate = useInvalidateTenants()

  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: { name: string; plan_id: number } }) =>
      centralApi.put<Resource<Tenant>>(`/tenants/${id}`, payload).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useTenantStatusAction() {
  const invalidate = useInvalidateTenants()

  return useMutation({
    mutationFn: ({ id, action }: { id: string; action: 'suspend' | 'activate' }) =>
      centralApi.post<Resource<Tenant>>(`/tenants/${id}/${action}`).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useDeleteTenant() {
  const invalidate = useInvalidateTenants()

  return useMutation({
    mutationFn: ({ id, confirm }: { id: string; confirm: string }) => centralApi.delete<undefined>(`/tenants/${id}`, { confirm }),
    onSuccess: invalidate,
  })
}
