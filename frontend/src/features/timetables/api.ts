import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { tenantApi } from '@/lib/api/client'
import type { ListParams, Paginated, Resource } from '@/lib/api/types'

export type TimetableType = 'fixed' | 'flexible'
export type BreakKind = 'fixed' | 'floating'
export type CycleUnit = 'day' | 'week'

export interface TimetableBreak {
  kind: BreakKind
  /** Minutes after the timetable start (fixed breaks only). */
  start_offset: number | null
  duration_minutes: number
  is_paid: boolean
}

/** Mirrors TimetableResource.php — all times are minutes from local midnight. */
export interface Timetable {
  id: number
  name: string
  code: string
  type: TimetableType
  color: string | null
  start_minute: number
  duration_minutes: number
  end_minute: number
  crosses_midnight: boolean
  required_minutes: number | null
  core_start_offset: number | null
  core_duration_minutes: number | null
  late_grace_minutes: number | null
  early_leave_grace_minutes: number | null
  check_in_before_minutes: number | null
  check_out_after_minutes: number | null
  min_work_minutes: number | null
  work_minutes?: number
  is_active: boolean
  breaks?: TimetableBreak[]
  shifts_count?: number
}

export interface EffectiveRules {
  late_grace_minutes: number
  early_leave_grace_minutes: number
  check_in_before_minutes: number
  check_out_after_minutes: number
}

export interface TimetableDetail {
  data: Timetable
  meta: { locked: boolean; effective: EffectiveRules }
}

export type TimetablePayload = Omit<
  Timetable,
  'id' | 'end_minute' | 'crosses_midnight' | 'work_minutes' | 'shifts_count' | 'breaks'
> & { breaks: TimetableBreak[] }

export interface ShiftDayTimetable {
  id: number
  name: string
  code: string
  color: string | null
  type: TimetableType
  start_minute: number
  duration_minutes: number
}

/** Mirrors ShiftResource.php — `days` lists working days only. */
export interface Shift {
  id: number
  name: string
  code: string
  cycle_unit: CycleUnit
  cycle_length: number
  cycle_days: number
  description: string | null
  is_active: boolean
  days?: { day_index: number; timetables: ShiftDayTimetable[] }[]
  working_days?: number
}

export interface ShiftPayload {
  name: string
  code: string
  cycle_unit: CycleUnit
  cycle_length: number
  description: string | null
  is_active: boolean
}

export interface PreviewDay {
  date: string
  day_index: number
  periods: {
    timetable: { id: number; name: string; code: string; color: string | null; type: TimetableType }
    starts_at: string
    ends_at: string
    minutes: number
  }[]
}

const keys = {
  timetables: ['tenant', 'timetables'] as const,
  shifts: ['tenant', 'shifts'] as const,
}

// ---------------------------------------------------------------- timetables

export function useTimetables(params: ListParams) {
  return useQuery({
    queryKey: [...keys.timetables, 'list', params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<Timetable>>('/timetables', params, signal),
    placeholderData: keepPreviousData,
  })
}

/** Active timetables for the shift editor. */
export function useTimetableOptions() {
  return useTimetables({ is_active: 1, per_page: 100, sort: 'start_minute' })
}

export function useTimetable(id: number | null) {
  return useQuery({
    queryKey: [...keys.timetables, 'detail', id],
    enabled: id !== null,
    queryFn: ({ signal }) => tenantApi.get<TimetableDetail>(`/timetables/${String(id)}`, undefined, signal),
  })
}

function useInvalidate() {
  const queryClient = useQueryClient()

  // Shifts embed timetable names/times, so both lists refresh together.
  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: keys.timetables }),
      queryClient.invalidateQueries({ queryKey: keys.shifts }),
    ])
}

export function useSaveTimetable() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: ({ id, payload }: { id?: number; payload: Partial<TimetablePayload> }) =>
      id
        ? tenantApi.put<TimetableDetail>(`/timetables/${String(id)}`, payload)
        : tenantApi.post<TimetableDetail>('/timetables', payload),
    onSuccess: invalidate,
  })
}

export function useDuplicateTimetable() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: (id: number) => tenantApi.post<TimetableDetail>(`/timetables/${String(id)}/duplicate`),
    onSuccess: invalidate,
  })
}

export function useDeleteTimetable() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: (id: number) => tenantApi.delete<undefined>(`/timetables/${String(id)}`),
    onSuccess: invalidate,
  })
}

// ---------------------------------------------------------------- shifts

export function useShifts(params: ListParams) {
  return useQuery({
    queryKey: [...keys.shifts, 'list', params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<Shift>>('/shifts', params, signal),
    placeholderData: keepPreviousData,
  })
}

export function useShift(id: number) {
  return useQuery({
    queryKey: [...keys.shifts, 'detail', id],
    queryFn: ({ signal }) =>
      tenantApi.get<Resource<Shift> & { meta: { locked: boolean } }>(`/shifts/${String(id)}`, undefined, signal),
  })
}

export function useShiftPreview(id: number, params: { from: string; to: string; anchor: string }) {
  return useQuery({
    queryKey: [...keys.shifts, 'preview', id, params],
    queryFn: ({ signal }) =>
      tenantApi.get<{ data: PreviewDay[]; meta: { timezone: string } }>(`/shifts/${String(id)}/preview`, params, signal),
    placeholderData: keepPreviousData,
  })
}

export function useSaveShift() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: ({ id, payload }: { id?: number; payload: Partial<ShiftPayload> }) =>
      (id
        ? tenantApi.put<Resource<Shift>>(`/shifts/${String(id)}`, payload)
        : tenantApi.post<Resource<Shift>>('/shifts', payload)
      ).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useSaveShiftDays(id: number) {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: (days: { day_index: number; timetable_ids: number[] }[]) =>
      tenantApi.put<Resource<Shift>>(`/shifts/${String(id)}/days`, { days }).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useDuplicateShift() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: (id: number) => tenantApi.post<Resource<Shift>>(`/shifts/${String(id)}/duplicate`).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useDeleteShift() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: (id: number) => tenantApi.delete<undefined>(`/shifts/${String(id)}`),
    onSuccess: invalidate,
  })
}
