import type { AttendanceStatus, CorrectionStatus, PunchRole, RawPunchState } from './api'

type Tone = 'success' | 'warning' | 'danger' | 'info' | 'holiday' | 'neutral'

/*
 * One palette everywhere (status-badge.tsx): present green, late amber,
 * absent red, holiday purple; leave (Module 6) blue.
 */
export const attendanceStatusLabel: Record<AttendanceStatus, string> = {
  present: 'Present',
  late: 'Late',
  early_leave: 'Left early',
  late_early: 'Late, left early',
  incomplete: 'Missing punch',
  absent: 'Absent',
  in_progress: 'In progress',
  on_leave: 'On leave',
  holiday: 'Holiday',
  rest: 'Rest day',
  day_off: 'Day off',
  unscheduled: 'No schedule',
  not_employed: 'Not employed',
}

export const attendanceStatusTone: Record<AttendanceStatus, Tone> = {
  present: 'success',
  late: 'warning',
  early_leave: 'warning',
  late_early: 'warning',
  incomplete: 'warning',
  absent: 'danger',
  in_progress: 'neutral',
  on_leave: 'info',
  holiday: 'holiday',
  rest: 'neutral',
  day_off: 'neutral',
  unscheduled: 'neutral',
  not_employed: 'neutral',
}

/** Background classes for calendar cells. */
export const attendanceCellClass: Record<AttendanceStatus, string> = {
  present: 'bg-status-present/10',
  late: 'bg-status-late/10',
  early_leave: 'bg-status-late/10',
  late_early: 'bg-status-late/10',
  incomplete: 'bg-status-late/10',
  absent: 'bg-status-absent/10',
  in_progress: 'bg-card',
  on_leave: 'bg-status-leave/10',
  holiday: 'bg-status-holiday/10',
  rest: 'bg-muted/50 text-muted-foreground',
  day_off: 'bg-muted/50 text-muted-foreground',
  unscheduled: 'bg-muted/30 text-muted-foreground',
  not_employed: 'bg-muted/30 text-muted-foreground/60',
}

export const punchRoleLabel: Record<PunchRole, string> = {
  in: 'In',
  out: 'Out',
  extra: 'Extra',
  duplicate: 'Double tap',
  unmatched: 'Outside schedule',
  ignored: 'Ignored',
}

export const correctionStatusTone: Record<CorrectionStatus, Tone> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'neutral',
}

export const rawPunchStateLabel: Record<RawPunchState, string> = {
  matched: 'Matched',
  unmatched: 'Unmatched',
  dismissed: 'Dismissed',
  resolved: 'Linked',
  pending: 'Processing',
}

export const flagLabel: Record<string, string> = {
  missing_in: 'No check-in',
  missing_out: 'No check-out',
  missed_period: 'A period was not worked',
  below_minimum: 'Below the minimum work time',
  off_day_work: 'Worked on a day off',
  half_day_leave: 'Half a day of leave; the other half was evaluated',
}

/** 428 → "7h 08m"; 0 → "—". */
export function formatMinutes(minutes: number): string {
  if (minutes <= 0) return '—'
  const h = Math.floor(minutes / 60)
  const m = minutes % 60

  return h > 0 ? `${String(h)}h ${String(m).padStart(2, '0')}m` : `${String(m)}m`
}
