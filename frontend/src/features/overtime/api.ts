import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError, tenantApi } from '@/lib/api/client'
import type { ListParams, Paginated, Resource } from '@/lib/api/types'

export type OvertimeStatus = 'pending' | 'approved' | 'rejected'
export type OvertimeKind = 'workday' | 'rest_day' | 'holiday'

/** Mirrors OvertimeRecordResource.php (minutes; rates in percent). */
export interface OvertimeRecord {
  id: number
  employee: { id: number; full_name: string; employee_code: string }
  work_date: string
  source: 'calculated' | 'manual'
  kind: OvertimeKind
  before_minutes: number
  after_minutes: number
  rest_day_minutes: number
  holiday_minutes: number
  calculated_minutes: number
  rates: { workday: number; rest_day: number; holiday: number }
  weighted_minutes: number
  status: OvertimeStatus
  approved_minutes: number | null
  approved_weighted_minutes: number | null
  decided_by: { id: number; name: string } | null
  decided_at: string | null
  decision_note: string | null
  changed_after_decision: boolean
  reason: string | null
  created_by: { id: number; name: string } | null
  can_decide: boolean
}

export interface OvertimeSummaryRow {
  employee: { id: number; full_name: string; employee_code: string } | null
  approved_minutes: number
  approved_weighted_minutes: number
  approved_workday_minutes: number
  approved_rest_day_minutes: number
  approved_holiday_minutes: number
  pending_minutes: number
  pending_count: number
}

const keys = {
  overtime: ['tenant', 'overtime'] as const,
}

function useInvalidateOvertime() {
  const queryClient = useQueryClient()

  return () => queryClient.invalidateQueries({ queryKey: keys.overtime })
}

export function useOvertime(params: ListParams) {
  return useQuery({
    queryKey: [...keys.overtime, 'list', params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<OvertimeRecord>>('/overtime', params, signal),
    placeholderData: keepPreviousData,
  })
}

export function useOvertimeSummary(params: ListParams) {
  return useQuery({
    queryKey: [...keys.overtime, 'summary', params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<OvertimeSummaryRow>>('/overtime/summary', params, signal),
    placeholderData: keepPreviousData,
  })
}

export function useEmployeeOvertime(employeeId: number, from: string, to: string, enabled = true) {
  return useQuery<OvertimeRecord[]>({
    queryKey: [...keys.overtime, 'employee', employeeId, from, to],
    queryFn: ({ signal }) =>
      tenantApi
        .get<{ data: OvertimeRecord[] }>(`/employees/${String(employeeId)}/overtime`, { from, to }, signal)
        .then((r) => r.data),
    placeholderData: keepPreviousData,
    enabled,
  })
}

/** Own overtime; null when the login is not linked to an employee. */
export function useMyOvertime(from: string, to: string, enabled = true) {
  return useQuery<OvertimeRecord[] | null>({
    queryKey: [...keys.overtime, 'me', from, to],
    queryFn: async ({ signal }) => {
      try {
        return (await tenantApi.get<{ data: OvertimeRecord[] }>('/me/overtime', { from, to }, signal)).data
      } catch (error) {
        if (error instanceof ApiError && error.status === 404) return null
        throw error
      }
    },
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useDecideOvertime() {
  const invalidate = useInvalidateOvertime()

  return useMutation({
    mutationFn: ({
      id,
      decision,
      minutes,
      note,
    }: {
      id: number
      decision: 'approve' | 'reject'
      minutes?: number | null
      note: string | null
    }) =>
      tenantApi
        .post<Resource<OvertimeRecord>>(
          `/overtime/${String(id)}/${decision}`,
          decision === 'approve' ? { minutes, note } : { note },
        )
        .then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useApproveOvertimeBatch() {
  const invalidate = useInvalidateOvertime()

  return useMutation({
    mutationFn: (ids: number[]) =>
      tenantApi.post<{ data: { approved: number; skipped: number } }>('/overtime/approve-batch', { ids }).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export interface OvertimeEntryPayload {
  employee_id?: number
  work_date?: string
  minutes: number
  kind: OvertimeKind
  reason: string
}

export function useSaveOvertimeEntry() {
  const invalidate = useInvalidateOvertime()

  return useMutation({
    mutationFn: ({ id, payload }: { id?: number; payload: OvertimeEntryPayload }) =>
      (id
        ? tenantApi.put<Resource<OvertimeRecord>>(`/overtime-entries/${String(id)}`, payload)
        : tenantApi.post<Resource<OvertimeRecord>>('/overtime-entries', payload)
      ).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useDeleteOvertimeEntry() {
  const invalidate = useInvalidateOvertime()

  return useMutation({
    mutationFn: (id: number) => tenantApi.delete<undefined>(`/overtime-entries/${String(id)}`),
    onSuccess: invalidate,
  })
}

export function useRecalculateOvertime() {
  const invalidate = useInvalidateOvertime()

  return useMutation({
    mutationFn: (payload: { from: string; to: string }) => tenantApi.post<{ message: string }>('/overtime/recalculate', payload),
    onSuccess: invalidate,
  })
}
