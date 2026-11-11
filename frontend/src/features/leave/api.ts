import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError, tenantApi } from '@/lib/api/client'
import type { ListParams, Paginated, Resource } from '@/lib/api/types'

/** Amounts are days in half-day steps (1.5); the API stores half-days. */

export type LeaveAccrual = 'none' | 'yearly' | 'monthly'
export type LeaveRequestStatus = 'pending' | 'approved' | 'rejected' | 'cancelled'
export type DayPortion = 'full' | 'first_half' | 'second_half'
export type LedgerKind = 'grant' | 'accrual' | 'carry_forward' | 'expiry' | 'request' | 'cancellation' | 'recount' | 'adjustment'

/** Mirrors LeaveTypeResource.php */
export interface LeaveType {
  id: number
  name: string
  code: string
  color: string | null
  is_paid: boolean
  accrual: LeaveAccrual
  entitlement_days: number
  carry_forward_max_days: number | null
  negative_limit_days: number
  unlimited: boolean
  is_active: boolean
}

export interface LeaveTypeBrief {
  id: number
  name: string
  code: string
  color: string | null
  is_paid: boolean
  unlimited: boolean
}

/** Mirrors LeaveRequestResource.php */
export interface LeaveRequest {
  id: number
  employee: { id: number; full_name: string; employee_code: string }
  type: LeaveTypeBrief
  start_date: string
  end_date: string
  half_start: boolean
  half_end: boolean
  days: number
  reason: string | null
  status: LeaveRequestStatus
  requested_by: { id: number; name: string } | null
  decided_by: { id: number; name: string } | null
  decided_at: string | null
  decision_note: string | null
  cancelled_at: string | null
  created_at: string
  counted_days?: { date: string; portion: DayPortion }[]
  can_decide: boolean
  can_cancel: boolean
  /** Present on lists and detail; empty when the viewer may not see the files. */
  attachments?: LeaveAttachment[]
  can_attach: boolean
}

/** Mirrors LeaveAttachmentResource.php. */
export interface LeaveAttachment {
  id: number
  name: string
  mime_type: string
  size_bytes: number
  uploaded_by: { id: number; name: string } | null
  created_at: string
  can_remove: boolean
}

export interface LeaveBalance {
  type: LeaveTypeBrief
  entitled: number
  used: number
  adjusted: number
  expired: number
  balance: number
  pending: number
  /** null for unlimited types. */
  available: number | null
}

export interface BalancesResponse {
  leave_year: number
  year_start: string
  year_end: string
  data: LeaveBalance[]
}

export interface LedgerEntry {
  id: number
  type: LeaveTypeBrief
  leave_year: number
  kind: LedgerKind
  days: number
  leave_request_id: number | null
  reason: string | null
  created_by: { id: number; name: string } | null
  created_at: string
}

export interface LeavePreview {
  days: number
  counted_days: { date: string; portion: DayPortion }[]
  years: { leave_year: number; days: number; available: number | null; after: number | null }[]
  problem: string | null
}

export interface LeavePayload {
  employee_id: number
  leave_type_id: number
  start_date: string
  end_date: string
  half_start: boolean
  half_end: boolean
  reason?: string | null
}

const keys = {
  types: ['tenant', 'leave-types'] as const,
  requests: ['tenant', 'leave-requests'] as const,
  balances: ['tenant', 'leave-balances'] as const,
  calendar: ['tenant', 'leave-calendar'] as const,
  attendance: ['tenant', 'attendance'] as const,
}

/** Leave changes balances, the calendar and (in the background) attendance. */
function useInvalidateLeave() {
  const queryClient = useQueryClient()

  return () =>
    Promise.all(
      [keys.requests, keys.balances, keys.calendar, keys.attendance].map((queryKey) =>
        queryClient.invalidateQueries({ queryKey }),
      ),
    )
}

// ---------------------------------------------------------------- types

export function useLeaveTypes(all = false) {
  return useQuery<LeaveType[]>({
    queryKey: [...keys.types, all],
    queryFn: ({ signal }) =>
      tenantApi.get<{ data: LeaveType[] }>('/leave-types', all ? { all: 1 } : undefined, signal).then((r) => r.data),
  })
}

export type LeaveTypePayload = Omit<LeaveType, 'id'>

export function useSaveLeaveType() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, payload }: { id?: number; payload: Partial<LeaveTypePayload> }) =>
      (id
        ? tenantApi.put<Resource<LeaveType>>(`/leave-types/${String(id)}`, payload)
        : tenantApi.post<Resource<LeaveType>>('/leave-types', payload)
      ).then((r) => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.types }),
  })
}

export function useDeleteLeaveType() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => tenantApi.delete<undefined>(`/leave-types/${String(id)}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.types }),
  })
}

// ---------------------------------------------------------------- requests

export function useLeaveRequests(params: ListParams) {
  return useQuery({
    queryKey: [...keys.requests, params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<LeaveRequest>>('/leave-requests', params, signal),
    placeholderData: keepPreviousData,
  })
}

/** Live preview of a request being filled in; disabled until the form is complete. */
export function useLeavePreview(payload: LeavePayload | null) {
  return useQuery<LeavePreview>({
    queryKey: [...keys.requests, 'preview', payload],
    queryFn: () => tenantApi.post<{ data: LeavePreview }>('/leave-requests/preview', payload).then((r) => r.data),
    enabled: payload !== null,
    retry: false,
  })
}

export function useRequestLeave() {
  const invalidate = useInvalidateLeave()

  return useMutation({
    mutationFn: (payload: LeavePayload) => tenantApi.post<Resource<LeaveRequest>>('/leave-requests', payload).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useLeaveAction() {
  const invalidate = useInvalidateLeave()

  return useMutation({
    mutationFn: ({ id, action, note }: { id: number; action: 'approve' | 'reject' | 'cancel'; note?: string | null }) =>
      tenantApi
        .post<Resource<LeaveRequest>>(`/leave-requests/${String(id)}/${action}`, action === 'cancel' ? undefined : { note })
        .then((r) => r.data),
    onSuccess: invalidate,
  })
}

// ---------------------------------------------------------------- attachments

/** Mirrors LeaveRules / LeaveAttachmentService limits. */
export const ATTACHMENT_LIMITS = { files: 3, bytes: 5 * 1024 * 1024, accept: '.pdf,.jpg,.jpeg,.png' } as const

export function useLeaveRequest(id: number | null) {
  return useQuery<LeaveRequest>({
    queryKey: [...keys.requests, 'detail', id],
    queryFn: ({ signal }) =>
      tenantApi.get<Resource<LeaveRequest>>(`/leave-requests/${String(id)}`, undefined, signal).then((r) => r.data),
    enabled: id !== null,
  })
}

export function useUploadLeaveAttachment(requestId: number) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (file: File) => {
      const body = new FormData()
      body.append('file', file)

      return tenantApi
        .post<Resource<LeaveAttachment>>(`/leave-requests/${String(requestId)}/attachments`, body)
        .then((r) => r.data)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.requests }),
  })
}

export function useDeleteLeaveAttachment(requestId: number) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (attachmentId: number) =>
      tenantApi.delete<undefined>(`/leave-requests/${String(requestId)}/attachments/${String(attachmentId)}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.requests }),
  })
}

/** Same-origin download; the session cookie authorises it. */
export function leaveAttachmentUrl(requestId: number, attachmentId: number): string {
  return `/api/v1/leave-requests/${String(requestId)}/attachments/${String(attachmentId)}`
}

export function useLeaveCalendar(params: { from: string; to: string; department_id?: string }) {
  return useQuery<LeaveRequest[]>({
    queryKey: [...keys.calendar, params],
    queryFn: ({ signal }) => tenantApi.get<{ data: LeaveRequest[] }>('/leave-calendar', params, signal).then((r) => r.data),
    placeholderData: keepPreviousData,
  })
}

// ---------------------------------------------------------------- balances and ledger

export function useEmployeeLeaveBalances(employeeId: number, year?: number) {
  return useQuery<BalancesResponse>({
    queryKey: [...keys.balances, employeeId, year],
    queryFn: ({ signal }) =>
      tenantApi.get<BalancesResponse>(`/employees/${String(employeeId)}/leave-balances`, year ? { year } : undefined, signal),
    placeholderData: keepPreviousData,
  })
}

/** Own balances; null when the login is not linked to an employee. */
export function useMyLeaveBalances(year?: number) {
  return useQuery<BalancesResponse | null>({
    queryKey: [...keys.balances, 'me', year],
    queryFn: async ({ signal }) => {
      try {
        return await tenantApi.get<BalancesResponse>('/me/leave-balances', year ? { year } : undefined, signal)
      } catch (error) {
        if (error instanceof ApiError && error.status === 404) return null
        throw error
      }
    },
    placeholderData: keepPreviousData,
  })
}

export function useLeaveLedger(employeeId: number, year?: number) {
  return useQuery<{ leave_year: number; data: LedgerEntry[] }>({
    queryKey: [...keys.balances, 'ledger', employeeId, year],
    queryFn: ({ signal }) =>
      tenantApi.get<{ leave_year: number; data: LedgerEntry[] }>(
        `/employees/${String(employeeId)}/leave-ledger`,
        year ? { year } : undefined,
        signal,
      ),
    placeholderData: keepPreviousData,
  })
}

export function useAdjustLeave() {
  const invalidate = useInvalidateLeave()

  return useMutation({
    mutationFn: (payload: { employee_id: number; leave_type_id: number; days: number; leave_year: number; reason: string }) =>
      tenantApi.post<Resource<LedgerEntry>>('/leave-adjustments', payload).then((r) => r.data),
    onSuccess: invalidate,
  })
}
