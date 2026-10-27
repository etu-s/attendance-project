import { formatLocalDate, type DisplayPreferences } from '@/lib/format'
import type { LedgerKind, LeaveAccrual, LeaveRequest, LeaveRequestStatus } from './api'

type Tone = 'success' | 'warning' | 'danger' | 'info' | 'holiday' | 'neutral'

/** 1 → "1 day", 1.5 → "1.5 days", -2 → "−2 days". */
export function formatDays(days: number): string {
  const value = Math.abs(days) % 1 === 0 ? String(Math.abs(days)) : Math.abs(days).toFixed(1)

  return `${days < 0 ? '−' : ''}${value} ${Math.abs(days) === 1 ? 'day' : 'days'}`
}

export const leaveStatusTone: Record<LeaveRequestStatus, Tone> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
  cancelled: 'neutral',
}

export const accrualLabel: Record<LeaveAccrual, string> = {
  none: 'Not earned automatically',
  yearly: 'Granted yearly',
  monthly: 'Earned monthly',
}

export const ledgerKindLabel: Record<LedgerKind, string> = {
  grant: 'Yearly grant',
  accrual: 'Monthly accrual',
  carry_forward: 'Carried forward',
  expiry: 'Expired at year end',
  request: 'Leave taken',
  cancellation: 'Leave cancelled',
  recount: 'Recounted (schedule change)',
  adjustment: 'Adjustment',
}

/** "2 Mar 2026 (afternoon) – 4 Mar 2026 (morning)" */
export function leaveRange(
  request: Pick<LeaveRequest, 'start_date' | 'end_date' | 'half_start' | 'half_end'>,
  prefs: DisplayPreferences,
): string {
  const from = `${formatLocalDate(request.start_date, prefs)}${request.half_start ? ' (afternoon)' : ''}`

  if (request.start_date === request.end_date) {
    if (request.half_start) return from
    return `${formatLocalDate(request.start_date, prefs)}${request.half_end ? ' (morning)' : ''}`
  }

  return `${from} – ${formatLocalDate(request.end_date, prefs)}${request.half_end ? ' (morning)' : ''}`
}
