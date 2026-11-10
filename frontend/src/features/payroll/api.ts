import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { tenantApi } from '@/lib/api/client'
import type { ListParams, Paginated, Resource } from '@/lib/api/types'

export type PayrollPeriodStatus = 'locked' | 'unlocked'

/** Mirrors PayrollPeriodResource.php. */
export interface PayrollPeriod {
  id: number
  name: string
  start_date: string
  end_date: string
  status: PayrollPeriodStatus
  locked_by: { id: number; name: string } | null
  locked_at: string | null
  unlocked_by: { id: number; name: string } | null
  unlocked_at: string | null
  unlock_reason: string | null
}

/** A locked period as listed in report meta (Core\Contracts\PeriodLock::overlapping). */
export interface LockedPeriodRef {
  id: number
  name: string
  start_date: string
  end_date: string
}

const keys = {
  periods: ['tenant', 'payroll-periods'] as const,
}

export function usePayrollPeriods(params: ListParams) {
  return useQuery({
    queryKey: [...keys.periods, params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<PayrollPeriod>>('/payroll-periods', params, signal),
    placeholderData: keepPreviousData,
  })
}

function useInvalidate() {
  const queryClient = useQueryClient()

  // Locks change what reports and attendance show, too.
  return () => queryClient.invalidateQueries({ queryKey: ['tenant'] })
}

export function useLockPayrollPeriod() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: (body: { name: string; start_date: string; end_date: string }) =>
      tenantApi.post<Resource<PayrollPeriod>>('/payroll-periods', body).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useRelockPayrollPeriod() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: (id: number) =>
      tenantApi.post<Resource<PayrollPeriod>>(`/payroll-periods/${String(id)}/lock`).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useUnlockPayrollPeriod() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      tenantApi.post<Resource<PayrollPeriod>>(`/payroll-periods/${String(id)}/unlock`, { reason }).then((r) => r.data),
    onSuccess: invalidate,
  })
}
