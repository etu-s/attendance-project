/**
 * Date/time display in the workspace timezone. The API always sends UTC ISO
 * strings; tenants choose their timezone and PHP-style formats in Settings
 * (general.date_format / general.time_format), which are mapped to Intl here.
 */

export interface DisplayPreferences {
  timezone: string
  dateFormat: string
  timeFormat: string
}

export const defaultPreferences: DisplayPreferences = {
  timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
  dateFormat: 'Y-m-d',
  timeFormat: 'H:i',
}

function parts(date: Date, timeZone: string, hour12: boolean): Record<string, string> {
  const formatted = new Intl.DateTimeFormat('en-GB', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12,
  }).formatToParts(date)

  const result: Record<string, string> = {}
  for (const part of formatted) {
    result[part.type] = part.value
  }

  result.monthShort = new Intl.DateTimeFormat('en-GB', { timeZone, month: 'short' }).format(date)

  return result
}

export function formatDate(iso: string | null | undefined, prefs: DisplayPreferences = defaultPreferences): string {
  if (!iso) {
    return '—'
  }

  const p = parts(new Date(iso), prefs.timezone, false)
  const { year = '', month = '', day = '', monthShort = '' } = p

  switch (prefs.dateFormat) {
    case 'd/m/Y':
      return `${day}/${month}/${year}`
    case 'm/d/Y':
      return `${month}/${day}/${year}`
    case 'd.m.Y':
      return `${day}.${month}.${year}`
    case 'd M Y':
      return `${day} ${monthShort} ${year}`
    default:
      return `${year}-${month}-${day}`
  }
}

export function formatTime(iso: string | null | undefined, prefs: DisplayPreferences = defaultPreferences): string {
  if (!iso) {
    return '—'
  }

  const twelveHour = prefs.timeFormat === 'h:i A'
  const p = parts(new Date(iso), prefs.timezone, twelveHour)
  const time = `${p.hour ?? ''}:${p.minute ?? ''}`

  return twelveHour ? `${time} ${(p.dayPeriod ?? '').toUpperCase()}` : time
}

export function formatDateTime(iso: string | null | undefined, prefs: DisplayPreferences = defaultPreferences): string {
  return iso ? `${formatDate(iso, prefs)} ${formatTime(iso, prefs)}` : '—'
}

/** Example rendering of a PHP date format, for setting pickers. */
export function exampleDate(format: string): string {
  return formatDate('2026-03-25T12:00:00Z', { ...defaultPreferences, timezone: 'UTC', dateFormat: format })
}

export function exampleTime(format: string): string {
  return formatTime('2026-03-25T15:30:00Z', { ...defaultPreferences, timezone: 'UTC', timeFormat: format })
}

export const weekdayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as const

/**
 * A calendar date from the API ("2026-02-01": hire dates, effective dates).
 * It has no time or timezone, so it is formatted as-is, never shifted.
 */
export function formatLocalDate(date: string | null | undefined, prefs: DisplayPreferences = defaultPreferences): string {
  if (!date) {
    return '—'
  }

  return formatDate(`${date}T12:00:00Z`, { ...prefs, timezone: 'UTC' })
}

/** Today's calendar date (Y-m-d) in the given timezone, e.g. the workspace timezone. */
export function todayIn(timezone: string): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date())
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? ''

  return `${get('year')}-${get('month')}-${get('day')}`
}
