import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { tenantApi } from '@/lib/api/client'
import type { ListParams, Paginated, Resource } from '@/lib/api/types'
import type { AttendanceStatus } from '@/features/attendance/api'

export type ReportKind = 'attendance-summary' | 'register' | 'exceptions' | 'leave' | 'overtime'
export type ExceptionType = 'late' | 'early_leave' | 'absent' | 'incomplete'
export type ExportFormat = 'csv' | 'xlsx'
export type ExportStatus = 'queued' | 'running' | 'completed' | 'failed' | 'expired'

export interface EmployeeRef {
  id: number
  full_name: string
  employee_code: string
}

/** Mirrors Reports\Support\Kpis.php (days and minutes; rates in percent, null when nothing to divide). */
export interface Kpis {
  working_days: number
  attended_days: number
  present_days: number
  late_days: number
  early_leave_days: number
  incomplete_days: number
  absent_days: number
  in_progress_days: number
  leave_days: number
  half_day_leave_days: number
  holiday_days: number
  rest_days: number
  scheduled_minutes: number
  worked_minutes: number
  late_minutes: number
  early_leave_minutes: number
  attendance_rate: number | null
  punctuality_rate: number | null
}

/** Mirrors DashboardService.php. */
export interface Dashboard {
  range: { from: string; to: string }
  source: 'rollup' | 'live'
  kpis: Kpis & {
    overtime_approved_minutes: number
    overtime_weighted_minutes: number
    overtime_pending_minutes: number
    overtime_pending_count: number
  }
  statuses: Record<Exclude<AttendanceStatus, 'not_employed'>, number>
  trend: { date: string; on_time: number; late: number; absent: number; on_leave: number; attendance_rate: number | null }[]
  departments: {
    department: { id: number; name: string; code: string } | null
    working_days: number
    attended_days: number
    absent_days: number
    late_days: number
    leave_days: number
    worked_minutes: number
    attendance_rate: number | null
    punctuality_rate: number | null
  }[]
}

export type SummaryRow = Kpis & { employee: EmployeeRef | null }

export interface RegisterCell {
  status: AttendanceStatus
  half_day_leave: boolean
  late_minutes: number
  early_leave_minutes: number
  worked_minutes: number
}

export interface RegisterRow {
  employee: EmployeeRef | null
  days: Record<string, RegisterCell>
  totals: Kpis
}

export interface RegisterMeta {
  month: string
  dates: string[]
  codes: Record<AttendanceStatus, string>
}

export interface ExceptionRow {
  id: number
  employee: EmployeeRef
  department: { id: number; name: string; code: string } | null
  work_date: string
  status: AttendanceStatus
  timezone: string
  first_in_at: string | null
  last_out_at: string | null
  scheduled_minutes: number
  worked_minutes: number
  late_minutes: number
  early_leave_minutes: number
}

export interface LeaveRow {
  employee: EmployeeRef | null
  /** Half days (1 = half a day). */
  half_days: number
  types: Record<string, number>
}

export interface LeaveMeta {
  leave_types: { id: number; name: string; code: string; color: string | null }[]
}

export interface OvertimeRow {
  employee: EmployeeRef | null
  approved_minutes: number
  approved_weighted_minutes: number
  approved_workday_minutes: number
  approved_rest_day_minutes: number
  approved_holiday_minutes: number
  pending_minutes: number
  pending_count: number
}

/** A report page: Paginated plus report-specific meta. */
export type ReportPage<T, M = object> = Omit<Paginated<T>, 'meta'> & { meta: Paginated<T>['meta'] & M }

/** Mirrors ReportExportResource.php. */
export interface ReportExport {
  id: number
  report: ReportKind
  report_label: string
  format: ExportFormat
  params: Record<string, string | number>
  status: ExportStatus
  file_name: string
  row_count: number | null
  size_bytes: number | null
  error: string | null
  downloadable: boolean
  created_at: string
  completed_at: string | null
  expires_at: string | null
}

export type ReportParams = Record<string, string | number | undefined>

const keys = {
  reports: ['tenant', 'reports'] as const,
  exports: ['tenant', 'reports', 'exports'] as const,
}

export function useDashboard(params: ReportParams) {
  return useQuery<Dashboard>({
    queryKey: [...keys.reports, 'dashboard', params],
    queryFn: ({ signal }) => tenantApi.get<Resource<Dashboard>>('/reports/dashboard', params, signal).then((r) => r.data),
    placeholderData: keepPreviousData,
  })
}

export function useReport<T, M = object>(kind: ReportKind, params: ListParams, enabled = true) {
  return useQuery({
    queryKey: [...keys.reports, kind, params],
    queryFn: ({ signal }) => tenantApi.get<ReportPage<T, M>>(`/reports/${kind}`, params, signal),
    placeholderData: keepPreviousData,
    enabled,
  })
}

/** The user's own exports; polls while any is still being generated. */
export function useReportExports(params: ListParams) {
  return useQuery({
    queryKey: [...keys.exports, params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<ReportExport>>('/reports/exports', params, signal),
    placeholderData: keepPreviousData,
    refetchInterval: (query) =>
      query.state.data?.data.some((e) => e.status === 'queued' || e.status === 'running') ? 3000 : false,
  })
}

export function useCreateExport() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (body: { report: ReportKind; format: ExportFormat } & ReportParams) =>
      tenantApi.post<Resource<ReportExport>>('/reports/exports', body).then((r) => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.exports }),
  })
}

/** Same-origin download; the session cookie authorises it. */
export function exportDownloadUrl(id: number): string {
  return `/api/v1/reports/exports/${String(id)}/download`
}
