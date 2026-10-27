import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError, tenantApi } from '@/lib/api/client'
import type { ListParams, Paginated, Resource } from '@/lib/api/types'

/** Mirrors App\Modules\Attendance\Enums\AttendanceStatus */
export const attendanceStatuses = [
  'present',
  'late',
  'early_leave',
  'late_early',
  'incomplete',
  'absent',
  'in_progress',
  'on_leave',
  'holiday',
  'rest',
  'day_off',
  'unscheduled',
  'not_employed',
] as const
export type AttendanceStatus = (typeof attendanceStatuses)[number]

/** Mirrors App\Modules\Attendance\Enums\PunchRole */
export type PunchRole = 'in' | 'out' | 'extra' | 'duplicate' | 'unmatched' | 'ignored'

interface EmployeeRef {
  id: number
  full_name: string
  employee_code: string
}

/** Mirrors AttendanceDayResource.php (periods/punches on the detail endpoint only). */
export interface AttendanceDay {
  id: number
  employee_id: number
  employee?: EmployeeRef
  work_date: string
  status: AttendanceStatus
  timezone: string
  holiday_name: string | null
  leave_name: string | null
  leave_portion: 'full' | 'first_half' | 'second_half' | null
  schedule_source: string | null
  shift_id: number | null
  department_id: number | null
  location_id: number | null
  first_in_at: string | null
  last_out_at: string | null
  scheduled_minutes: number
  worked_minutes: number
  late_minutes: number
  early_leave_minutes: number
  unmatched_punches: number
  flags: string[]
  calculated_at: string
  periods?: AttendancePeriod[]
  punches?: AttendancePunch[]
}

export interface AttendancePeriod {
  id: number
  position: number
  timetable_id: number | null
  timetable_name: string
  flexible: boolean
  scheduled_start_at: string
  scheduled_end_at: string
  in_at: string | null
  out_at: string | null
  worked_minutes: number
  break_minutes: number
  late_minutes: number
  early_leave_minutes: number
}

export interface AttendancePunch {
  id: number
  raw_punch_id: number | null
  punch_correction_id: number | null
  attendance_period_id: number | null
  punched_at: string
  role: PunchRole
  source: string | null
}

export type CorrectionStatus = 'pending' | 'approved' | 'rejected'
export type CorrectionKind = 'add' | 'ignore'

/** Mirrors PunchCorrectionResource.php */
export interface PunchCorrection {
  id: number
  employee: EmployeeRef
  work_date: string
  kind: CorrectionKind
  punched_at: string | null
  raw_punch: { id: number; punched_at: string } | null
  reason: string
  status: CorrectionStatus
  requested_by: { id: number; name: string } | null
  decided_by: { id: number; name: string } | null
  decided_at: string | null
  decision_note: string | null
  created_at: string
  can_decide: boolean
}

export type PunchSourceKind = 'device' | 'integration'

/** Mirrors PunchSourceResource.php (never contains the key). */
export interface PunchSource {
  id: number
  name: string
  kind: PunchSourceKind
  key_hint: string
  signed: boolean
  ip_allowlist: string[]
  is_active: boolean
  last_used_at: string | null
  created_at: string
}

/** The create / rotate-key answer: the only time the key and secret are shown. */
export interface PunchSourceSecrets {
  data: PunchSource
  key: string
  signing_secret: string | null
}

export type RawPunchState = 'matched' | 'unmatched' | 'dismissed' | 'resolved' | 'pending'

/** Mirrors RawPunchResource.php */
export interface RawPunch {
  id: number
  source: { id: number; name: string }
  employee_identifier: string
  punched_at: string
  direction: 'in' | 'out' | null
  idempotency_key: string | null
  meta: Record<string, unknown> | null
  too_old: boolean
  received_at: string
  state: RawPunchState
  employee: EmployeeRef | null
}

export type OrphanStatus = 'open' | 'resolved' | 'dismissed'

/** Mirrors OrphanPunchResource.php */
export interface OrphanPunch {
  id: number
  raw_punch_id: number
  employee_identifier: string
  punched_at: string
  too_old: boolean
  source: { id: number; name: string }
  status: OrphanStatus
  employee: EmployeeRef | null
  decided_at: string | null
}

/** Mirrors PunchIdentifierResource.php */
export interface PunchIdentifier {
  id: number
  employee_id: number
  identifier: string
  label: string | null
  created_at: string
}

export interface AttendanceSummary {
  date: string
  counts: Record<AttendanceStatus, number>
  total: number
}

const keys = {
  attendance: ['tenant', 'attendance'] as const,
  corrections: ['tenant', 'punch-corrections'] as const,
  sources: ['tenant', 'punch-sources'] as const,
  raw: ['tenant', 'raw-punches'] as const,
  orphans: ['tenant', 'orphan-punches'] as const,
  identifiers: ['tenant', 'punch-identifiers'] as const,
}

/** Corrections, links and recalculations change attendance in the background (queue). */
function useInvalidateAttendance() {
  const queryClient = useQueryClient()

  return () =>
    Promise.all(
      [keys.attendance, keys.corrections, keys.raw, keys.orphans, keys.identifiers].map((queryKey) =>
        queryClient.invalidateQueries({ queryKey }),
      ),
    )
}

// ---------------------------------------------------------------- attendance

/** The Today board refreshes every 30 seconds (live push comes with Reverb, later). */
export const LIVE_REFRESH_MS = 30_000

export function useAttendance(params: ListParams, options: { live?: boolean } = {}) {
  return useQuery({
    queryKey: [...keys.attendance, 'list', params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<AttendanceDay>>('/attendance', params, signal),
    placeholderData: keepPreviousData,
    refetchInterval: options.live ? LIVE_REFRESH_MS : false,
  })
}

export function useAttendanceSummary(params: { date: string; department_id?: string; group_id?: string }, live = false) {
  return useQuery<AttendanceSummary>({
    queryKey: [...keys.attendance, 'summary', params],
    queryFn: ({ signal }) =>
      tenantApi.get<{ data: AttendanceSummary }>('/attendance/summary', params, signal).then((r) => r.data),
    placeholderData: keepPreviousData,
    refetchInterval: live ? LIVE_REFRESH_MS : false,
  })
}

export function useAttendanceDay(id: number | null) {
  return useQuery({
    queryKey: [...keys.attendance, 'day', id],
    queryFn: ({ signal }) =>
      tenantApi.get<{ data: AttendanceDay; corrections: PunchCorrection[] }>(`/attendance/${String(id)}`, undefined, signal),
    enabled: id !== null,
  })
}

export function useEmployeeAttendance(employeeId: number, from: string, to: string) {
  return useQuery<AttendanceDay[]>({
    queryKey: [...keys.attendance, 'employee', employeeId, from, to],
    queryFn: ({ signal }) =>
      tenantApi
        .get<{ data: AttendanceDay[] }>(`/employees/${String(employeeId)}/attendance`, { from, to }, signal)
        .then((r) => r.data),
    placeholderData: keepPreviousData,
  })
}

/** Own attendance; null when the login is not linked to an employee. */
export function useMyAttendance(from: string, to: string) {
  return useQuery<AttendanceDay[] | null>({
    queryKey: [...keys.attendance, 'me', from, to],
    queryFn: async ({ signal }) => {
      try {
        return (await tenantApi.get<{ data: AttendanceDay[] }>('/me/attendance', { from, to }, signal)).data
      } catch (error) {
        if (error instanceof ApiError && error.status === 404) return null
        throw error
      }
    },
    placeholderData: keepPreviousData,
  })
}

export function useRecalculate() {
  const invalidate = useInvalidateAttendance()

  return useMutation({
    mutationFn: (payload: { from: string; to: string; employee_id?: number | null }) =>
      tenantApi.post<{ message: string }>('/attendance/recalculate', payload),
    onSuccess: invalidate,
  })
}

// ---------------------------------------------------------------- corrections

export function useCorrections(params: ListParams) {
  return useQuery({
    queryKey: [...keys.corrections, params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<PunchCorrection>>('/punch-corrections', params, signal),
    placeholderData: keepPreviousData,
  })
}

export interface CorrectionPayload {
  employee_id: number
  work_date: string
  kind: CorrectionKind
  punched_at?: string
  raw_punch_id?: number
  reason: string
}

export function useRequestCorrection() {
  const invalidate = useInvalidateAttendance()

  return useMutation({
    mutationFn: (payload: CorrectionPayload) =>
      tenantApi.post<Resource<PunchCorrection>>('/punch-corrections', payload).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useDecideCorrection() {
  const invalidate = useInvalidateAttendance()

  return useMutation({
    mutationFn: ({ id, decision, note }: { id: number; decision: 'approve' | 'reject'; note: string | null }) =>
      tenantApi.post<Resource<PunchCorrection>>(`/punch-corrections/${String(id)}/${decision}`, { note }).then((r) => r.data),
    onSuccess: invalidate,
  })
}

// ---------------------------------------------------------------- punch sources

export function usePunchSources() {
  return useQuery<PunchSource[]>({
    queryKey: keys.sources,
    queryFn: ({ signal }) => tenantApi.get<{ data: PunchSource[] }>('/punch-sources', undefined, signal).then((r) => r.data),
  })
}

export interface PunchSourcePayload {
  name: string
  kind: PunchSourceKind
  ip_allowlist: string[]
  signed?: boolean
  is_active?: boolean
}

export function useSavePunchSource() {
  const queryClient = useQueryClient()

  return useMutation({
    /** Creating answers the one-time key (and secret); updating answers null. */
    mutationFn: ({ id, payload }: { id?: number; payload: PunchSourcePayload }): Promise<PunchSourceSecrets | null> =>
      id
        ? tenantApi.put<Resource<PunchSource>>(`/punch-sources/${String(id)}`, payload).then(() => null)
        : tenantApi.post<PunchSourceSecrets>('/punch-sources', payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.sources }),
  })
}

export function useRotatePunchSourceKey() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => tenantApi.post<PunchSourceSecrets>(`/punch-sources/${String(id)}/rotate-key`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.sources }),
  })
}

export function useDeletePunchSource() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => tenantApi.delete<undefined>(`/punch-sources/${String(id)}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.sources }),
  })
}

// ---------------------------------------------------------------- raw and unmatched punches

export function useRawPunches(params: ListParams) {
  return useQuery({
    queryKey: [...keys.raw, params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<RawPunch>>('/raw-punches', params, signal),
    placeholderData: keepPreviousData,
  })
}

export function useOrphanPunches(params: ListParams) {
  return useQuery({
    queryKey: [...keys.orphans, params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<OrphanPunch>>('/orphan-punches', params, signal),
    placeholderData: keepPreviousData,
  })
}

export function useLinkOrphan() {
  const invalidate = useInvalidateAttendance()

  return useMutation({
    mutationFn: ({ id, employeeId }: { id: number; employeeId: number }) =>
      tenantApi.post<{ linked: number }>(`/orphan-punches/${String(id)}/link`, { employee_id: employeeId }),
    onSuccess: invalidate,
  })
}

export function useDismissOrphan() {
  const invalidate = useInvalidateAttendance()

  return useMutation({
    mutationFn: (id: number) => tenantApi.post<Resource<OrphanPunch>>(`/orphan-punches/${String(id)}/dismiss`),
    onSuccess: invalidate,
  })
}

// ---------------------------------------------------------------- punch identifiers

export function usePunchIdentifiers(employeeId: number, enabled = true) {
  return useQuery<PunchIdentifier[]>({
    queryKey: [...keys.identifiers, employeeId],
    queryFn: ({ signal }) =>
      tenantApi
        .get<{ data: PunchIdentifier[] }>(`/employees/${String(employeeId)}/punch-identifiers`, undefined, signal)
        .then((r) => r.data),
    enabled,
  })
}

export function useAddPunchIdentifier(employeeId: number) {
  const invalidate = useInvalidateAttendance()

  return useMutation({
    mutationFn: (payload: { identifier: string; label: string | null }) =>
      tenantApi
        .post<Resource<PunchIdentifier>>(`/employees/${String(employeeId)}/punch-identifiers`, payload)
        .then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useDeletePunchIdentifier() {
  const invalidate = useInvalidateAttendance()

  return useMutation({
    mutationFn: (id: number) => tenantApi.delete<undefined>(`/punch-identifiers/${String(id)}`),
    onSuccess: invalidate,
  })
}
