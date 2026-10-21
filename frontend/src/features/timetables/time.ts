/**
 * Timetables store times as whole minutes from local midnight (09:30 = 570);
 * an end past 1440 falls on the next day. These helpers convert for display
 * and for <input type="time"> values.
 */

export function minutesToTime(minutes: number): string {
  const m = ((minutes % 1440) + 1440) % 1440

  return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`
}

/** "HH:MM" (24 h) to minutes; null when not a valid time. */
export function timeToMinutes(value: string): number | null {
  const match = /^(\d{1,2}):([0-5]\d)$/.exec(value.trim())
  if (!match) return null
  const hours = Number(match[1])
  if (hours > 23) return null

  return hours * 60 + Number(match[2])
}

/** "8:30" or "8" (hours) to minutes, for durations that may exceed a day's clock. */
export function durationToMinutes(value: string): number | null {
  const trimmed = value.trim()
  if (/^\d{1,2}$/.test(trimmed)) return Number(trimmed) * 60
  const match = /^(\d{1,2}):([0-5]\d)$/.exec(trimmed)

  return match ? Number(match[1]) * 60 + Number(match[2]) : null
}

export function minutesToDuration(minutes: number): string {
  return `${String(Math.floor(minutes / 60))}:${String(minutes % 60).padStart(2, '0')}`
}

/** "8 h 30 min" */
export function formatDuration(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  if (h === 0) return `${String(m)} min`

  return m === 0 ? `${String(h)} h` : `${String(h)} h ${String(m)} min`
}

/** "22:00–06:00 (+1 day)" */
export function timeRange(start: number, duration: number): string {
  const end = start + duration
  const nextDay = end > 1440 ? ' (+1 day)' : ''

  return `${minutesToTime(start)}–${minutesToTime(end)}${nextDay}`
}

/** Duration from a start to an end clock time; an end at or before the start is the next day. */
export function spanMinutes(start: number, end: number): number {
  return (end - start + 1440) % 1440 || 1440
}

export const weekdayShort = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as const

/** Label of a cycle day: "Mon" / "Week 2 · Mon" for week cycles, "Day 5" for day cycles. */
export function cycleDayLabel(index: number, unit: 'day' | 'week', length: number): string {
  if (unit === 'day') return `Day ${String(index + 1)}`
  const day = weekdayShort[index % 7] ?? ''

  return length > 1 ? `Week ${String(Math.floor(index / 7) + 1)} · ${day}` : day
}

/** "1 week", "8 days" */
export function cycleLabel(shift: { cycle_unit: 'day' | 'week'; cycle_length: number }): string {
  const plural = shift.cycle_length === 1 ? '' : 's'

  return `${String(shift.cycle_length)} ${shift.cycle_unit}${plural}`
}
