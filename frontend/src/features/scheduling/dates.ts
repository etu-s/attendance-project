/**
 * Calendar-date arithmetic on "YYYY-MM-DD" strings. Done at noon UTC so no
 * local timezone or DST shift can move a date.
 */

function toDate(date: string): Date {
  return new Date(`${date}T12:00:00Z`)
}

function toString(d: Date): string {
  return d.toISOString().slice(0, 10)
}

export function addDays(date: string, days: number): string {
  const d = toDate(date)
  d.setUTCDate(d.getUTCDate() + days)

  return toString(d)
}

export function monthStart(date: string): string {
  return `${date.slice(0, 7)}-01`
}

export function addMonths(month: string, months: number): string {
  const d = toDate(monthStart(month))
  d.setUTCMonth(d.getUTCMonth() + months)

  return toString(d)
}

export function monthEnd(month: string): string {
  return addDays(addMonths(month, 1), -1)
}

/** 0 = Sunday … 6 = Saturday */
export function weekday(date: string): number {
  return toDate(date).getUTCDay()
}

/** The first day of the week containing `date` (weekStart: 0 = Sunday, 1 = Monday, …). */
export function weekStartOf(date: string, weekStart: number): string {
  return addDays(date, -((weekday(date) - weekStart + 7) % 7))
}

export function monthLabel(month: string): string {
  return toDate(monthStart(month)).toLocaleDateString('en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' })
}

export function weekdayLabel(date: string, style: 'short' | 'narrow' = 'short'): string {
  return toDate(date).toLocaleDateString('en-GB', { weekday: style, timeZone: 'UTC' })
}

export function dayOfMonth(date: string): number {
  return toDate(date).getUTCDate()
}
