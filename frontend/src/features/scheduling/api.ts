import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError, tenantApi } from '@/lib/api/client'
import type { ListParams, Paginated, Resource } from '@/lib/api/types'

export type ScheduleTargetType = 'organization' | 'department' | 'group' | 'employee'

export const targetLabels: Record<ScheduleTargetType, string> = {
  organization: 'Organization default',
  department: 'Department',
  group: 'Group',
  employee: 'Employee',
}

/** Mirrors ScheduleAssignmentResource.php */
export interface ScheduleAssignment {
  id: number
  target: { type: ScheduleTargetType; id: number | null; name: string | null }
  shift: { id: number; name: string; code: string; cycle_unit: 'day' | 'week'; cycle_length: number }
  anchor_date: string
  effective_from: string
  effective_to: string | null
}

/** Mirrors TemporaryScheduleResource.php */
export interface TemporarySchedule {
  id: number
  employee_id: number
  /** Only on the cross-employee list (GET /temporary-schedules). */
  employee?: { id: number; employee_code: string; full_name: string }
  date_from: string
  date_to: string
  day_off: boolean
  shift: { id: number; name: string; code: string } | null
  anchor_date: string | null
  reason: string | null
}

/** Mirrors HolidayResource.php */
export interface Holiday {
  id: number
  date: string
  occurs_on: string
  name: string
  recurs_yearly: boolean
  location: { id: number; name: string } | null
}

/** Mirrors App\Modules\Scheduling\Enums\DayStatus */
export type DayStatus = 'working' | 'rest' | 'holiday' | 'day_off' | 'unscheduled' | 'not_employed'

/** Mirrors ResolvedDayPresenter.php */
export interface ResolvedDay {
  date: string
  status: DayStatus
  source: { type: ScheduleTargetType | 'temporary'; id: number | null; name: string | null; inherited_from: string | null } | null
  shift: { id: number; name: string } | null
  holiday: string | null
  /** Location timezone if set, else the workspace timezone. */
  timezone: string
  periods: {
    timetable: { id: number; name: string; color: string | null; type: 'fixed' | 'flexible' }
    starts_at: string
    ends_at: string
    minutes: number
  }[]
}

export interface RosterResponse {
  dates: string[]
  data: { employee: { id: number; name: string; employee_code: string }; days: ResolvedDay[] }[]
  meta: Paginated<unknown>['meta']
}

const keys = {
  assignments: ['tenant', 'schedule-assignments'] as const,
  resolved: ['tenant', 'schedules'] as const,
  temporary: ['tenant', 'temporary-schedules'] as const,
  holidays: ['tenant', 'holidays'] as const,
}

/** Anything that changes schedules invalidates every resolved view. */
function useInvalidate() {
  const queryClient = useQueryClient()

  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: keys.assignments }),
      queryClient.invalidateQueries({ queryKey: keys.resolved }),
      queryClient.invalidateQueries({ queryKey: keys.temporary }),
      queryClient.invalidateQueries({ queryKey: keys.holidays }),
      // Shifts become read-only once a schedule using them starts.
      queryClient.invalidateQueries({ queryKey: ['tenant', 'shifts'] }),
    ])
}

// ---------------------------------------------------------------- assignments

export function useScheduleAssignments(params: ListParams) {
  return useQuery({
    queryKey: [...keys.assignments, params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<ScheduleAssignment>>('/schedule-assignments', params, signal),
    placeholderData: keepPreviousData,
  })
}

export interface AssignPayload {
  target_type: ScheduleTargetType
  target_id: number | null
  shift_id: number
  effective_from: string
  effective_to: string | null
  anchor_date: string | null
}

export function useAssignSchedule() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: (payload: AssignPayload) =>
      tenantApi.post<Resource<ScheduleAssignment>>('/schedule-assignments', payload).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useUpdateAssignment() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: ({
      id,
      payload,
    }: {
      id: number
      payload: { shift_id?: number; anchor_date?: string; effective_to?: string | null }
    }) => tenantApi.put<Resource<ScheduleAssignment>>(`/schedule-assignments/${String(id)}`, payload).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useDeleteAssignment() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: (id: number) => tenantApi.delete<undefined>(`/schedule-assignments/${String(id)}`),
    onSuccess: invalidate,
  })
}

// ---------------------------------------------------------------- resolved schedules

export function useEmployeeSchedule(employeeId: number, from: string, to: string) {
  return useQuery<ResolvedDay[]>({
    queryKey: [...keys.resolved, 'employee', employeeId, from, to],
    queryFn: ({ signal }) =>
      tenantApi
        .get<{ data: ResolvedDay[] }>(`/employees/${String(employeeId)}/schedule`, { from, to }, signal)
        .then((r) => r.data),
    placeholderData: keepPreviousData,
  })
}

/** Own schedule; null when the login is not linked to an employee. */
export function useMySchedule(from: string, to: string) {
  return useQuery<ResolvedDay[] | null>({
    queryKey: [...keys.resolved, 'me', from, to],
    queryFn: async ({ signal }) => {
      try {
        return (await tenantApi.get<{ data: ResolvedDay[] }>('/me/schedule', { from, to }, signal)).data
      } catch (error) {
        if (error instanceof ApiError && error.status === 404) return null
        throw error
      }
    },
    placeholderData: keepPreviousData,
  })
}

export function useRoster(params: {
  from: string
  days: number
  page?: number
  department_id?: string
  group_id?: string
  search?: string
}) {
  return useQuery({
    queryKey: [...keys.resolved, 'roster', params],
    queryFn: ({ signal }) => tenantApi.get<RosterResponse>('/roster', params, signal),
    placeholderData: keepPreviousData,
  })
}

// ---------------------------------------------------------------- temporary schedules

export function useTemporarySchedules(employeeId: number) {
  return useQuery({
    queryKey: [...keys.temporary, employeeId],
    queryFn: ({ signal }) =>
      tenantApi
        .get<{ data: TemporarySchedule[] }>(`/employees/${String(employeeId)}/temporary-schedules`, undefined, signal)
        .then((r) => r.data),
  })
}

export function useAllTemporarySchedules(params: ListParams) {
  return useQuery({
    queryKey: [...keys.temporary, 'all', params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<TemporarySchedule>>('/temporary-schedules', params, signal),
    placeholderData: keepPreviousData,
  })
}

export interface TemporaryPayload {
  date_from: string
  date_to: string
  shift_id: number | null
  anchor_date: string | null
  reason: string | null
}

export function useSaveTemporary() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: ({ id, employeeId, payload }: { id?: number; employeeId: number; payload: TemporaryPayload }) =>
      (id
        ? tenantApi.put<Resource<TemporarySchedule>>(`/temporary-schedules/${String(id)}`, payload)
        : tenantApi.post<Resource<TemporarySchedule>>(`/employees/${String(employeeId)}/temporary-schedules`, payload)
      ).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useDeleteTemporary() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: (id: number) => tenantApi.delete<undefined>(`/temporary-schedules/${String(id)}`),
    onSuccess: invalidate,
  })
}

// ---------------------------------------------------------------- holidays

export function useHolidays(year: number) {
  return useQuery<Holiday[]>({
    queryKey: [...keys.holidays, year],
    queryFn: ({ signal }) => tenantApi.get<{ data: Holiday[] }>('/holidays', { year }, signal).then((r) => r.data),
    placeholderData: keepPreviousData,
  })
}

export interface HolidayPayload {
  date: string
  name: string
  location_id: number | null
  recurs_yearly: boolean
}

export function useSaveHoliday() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: ({ id, payload }: { id?: number; payload: HolidayPayload }) =>
      (id
        ? tenantApi.put<Resource<Holiday>>(`/holidays/${String(id)}`, payload)
        : tenantApi.post<Resource<Holiday>>('/holidays', payload)
      ).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useDeleteHoliday() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: (id: number) => tenantApi.delete<undefined>(`/holidays/${String(id)}`),
    onSuccess: invalidate,
  })
}
