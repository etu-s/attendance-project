import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { centralApi } from '@/lib/api/client'
import type { ListParams, Paginated, Resource } from '@/lib/api/types'

/** Mirrors PlanResource.php */
export interface Plan {
  id: number
  name: string
  slug: string
  /** null = unlimited */
  max_employees: number | null
  features: string[]
  is_active: boolean
  tenants_count?: number
  created_at: string
  updated_at: string
}

export interface PlanPayload {
  name: string
  slug: string
  max_employees: number | null
  features: string[]
  is_active: boolean
}

const plansKey = ['central', 'plans'] as const

export function usePlans(params: ListParams) {
  return useQuery({
    queryKey: [...plansKey, params],
    queryFn: ({ signal }) => centralApi.get<Paginated<Plan>>('/plans', params, signal),
    placeholderData: keepPreviousData,
  })
}

/** Active plans for pickers (tenant create/edit). */
export function useActivePlans() {
  return usePlans({ is_active: 1, per_page: 100, sort: 'name' })
}

function useInvalidatePlans() {
  const queryClient = useQueryClient()

  return () => queryClient.invalidateQueries({ queryKey: plansKey })
}

export function useSavePlan() {
  const invalidate = useInvalidatePlans()

  return useMutation({
    mutationFn: ({ id, payload }: { id?: number; payload: PlanPayload }) =>
      (id ? centralApi.put<Resource<Plan>>(`/plans/${id}`, payload) : centralApi.post<Resource<Plan>>('/plans', payload)).then(
        (r) => r.data,
      ),
    onSuccess: invalidate,
  })
}

export function useDeletePlan() {
  const invalidate = useInvalidatePlans()

  return useMutation({
    mutationFn: (id: number) => centralApi.delete<undefined>(`/plans/${id}`),
    onSuccess: invalidate,
  })
}
