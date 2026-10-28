import type { OvertimeKind, OvertimeRecord, OvertimeStatus } from './api'

type Tone = 'success' | 'warning' | 'danger' | 'info' | 'holiday' | 'neutral'

export const overtimeStatusTone: Record<OvertimeStatus, Tone> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'neutral',
}

export const overtimeKindLabel: Record<OvertimeKind, string> = {
  workday: 'Working day',
  rest_day: 'Rest day',
  holiday: 'Holiday',
}

/** 90 → "1h 30m", 45 → "45m", 0 → "0m". */
export function formatDuration(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60

  if (h === 0) return `${String(m)}m`
  return m === 0 ? `${String(h)}h` : `${String(h)}h ${String(m).padStart(2, '0')}m`
}

/** "After shift 1h · before shift 30m" — what the calculated minutes consist of. */
export function overtimeBreakdown(record: OvertimeRecord): string {
  const parts: string[] = []
  if (record.after_minutes > 0) parts.push(`after shift ${formatDuration(record.after_minutes)}`)
  if (record.before_minutes > 0) parts.push(`before shift ${formatDuration(record.before_minutes)}`)
  if (record.after_minutes + record.before_minutes > 0) parts.push(`at ${String(record.rates.workday)}%`)
  if (record.rest_day_minutes > 0)
    parts.push(`rest day ${formatDuration(record.rest_day_minutes)}`, `at ${String(record.rates.rest_day)}%`)
  if (record.holiday_minutes > 0)
    parts.push(`holiday ${formatDuration(record.holiday_minutes)}`, `at ${String(record.rates.holiday)}%`)

  const text = parts.join(' · ')
  return text === '' ? 'No overtime left' : text.charAt(0).toUpperCase() + text.slice(1)
}
