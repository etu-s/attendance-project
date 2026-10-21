import { addDays } from '@/features/scheduling/dates'

/**
 * Wall-clock time in a timezone ⇄ UTC, for entering punch times. The day's
 * timezone (the employee's location) decides what "17:00" means.
 */

function offsetMinutes(utcMillis: number, timeZone: string): number {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).formatToParts(new Date(utcMillis))
  const get = (type: string) => Number(parts.find((p) => p.type === type)?.value ?? 0)
  const asUtc = Date.UTC(get('year'), get('month') - 1, get('day'), get('hour'), get('minute'), get('second'))

  return Math.round((asUtc - utcMillis) / 60_000)
}

/** "2026-03-02" + "17:00" in Asia/Karachi → "2026-03-02T12:00:00Z". */
export function zonedToUtcIso(date: string, time: string, timeZone: string): string {
  const [y = 0, mo = 1, d = 1] = date.split('-').map(Number)
  const [h = 0, mi = 0] = time.split(':').map(Number)
  const wall = Date.UTC(y, mo - 1, d, h, mi)
  // Two passes settle the offset around DST changes.
  let utc = wall - offsetMinutes(wall, timeZone) * 60_000
  utc = wall - offsetMinutes(utc, timeZone) * 60_000

  return new Date(utc).toISOString().replace(/\.\d{3}Z$/, 'Z')
}

/** Short zone name for labels, e.g. "PKT" or "GMT+5". */
export function zoneLabel(timeZone: string): string {
  return (
    new Intl.DateTimeFormat('en-US', { timeZone, timeZoneName: 'short' })
      .formatToParts(new Date())
      .find((p) => p.type === 'timeZoneName')?.value ?? timeZone
  )
}

/** Every Y-m-d from `from` to `to`, inclusive (string order is date order for Y-m-d). */
export function datesBetween(from: string, to: string): string[] {
  const dates: string[] = []
  for (let date = from; date <= to; date = addDays(date, 1)) {
    dates.push(date)
  }

  return dates
}
