import { ColorDot } from '@/features/timetables/timetables-page'
import { formatTime, type DisplayPreferences } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { ResolvedDay } from './api'
import { dayStatusLabel, sourceLabel } from './day-status'

/**
 * One resolved day, compact. Times are shown in the day's own timezone (the
 * employee's location, else the workspace), which is where they apply.
 */
export function DayContent({
  day,
  prefs,
  showSource = false,
}: {
  day: ResolvedDay
  prefs: DisplayPreferences
  showSource?: boolean
}) {
  const local = { ...prefs, timezone: day.timezone }

  return (
    <div className="space-y-0.5 text-xs">
      {day.periods.length > 0 && day.status !== 'holiday' ? (
        day.periods.map((p, i) => (
          <p key={i} className="flex items-center gap-1.5">
            <ColorDot color={p.timetable.color} />
            <span className="tabular-nums">
              {formatTime(p.starts_at, local)}–{formatTime(p.ends_at, local)}
            </span>
          </p>
        ))
      ) : (
        <p
          className={cn(
            day.status === 'holiday' && 'text-status-holiday font-medium',
            day.status === 'day_off' && 'text-status-leave',
          )}
        >
          {day.status === 'holiday' ? (day.holiday ?? 'Holiday') : dayStatusLabel[day.status]}
        </p>
      )}
      {showSource && day.source && <p className="text-muted-foreground truncate">{sourceLabel(day)}</p>}
    </div>
  )
}
