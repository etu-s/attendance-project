import { Skeleton } from '@/components/ui/skeleton'
import { addDays, dayOfMonth, weekdayLabel, weekStartOf } from '@/features/scheduling/dates'
import { formatTime, type DisplayPreferences } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { AttendanceDay } from './api'
import { attendanceCellClass, attendanceStatusLabel, formatMinutes } from './status'

function DaySummary({ day, prefs }: { day: AttendanceDay; prefs: DisplayPreferences }) {
  const local = { ...prefs, timezone: day.timezone }
  const times = day.first_in_at || day.last_out_at

  return (
    <div className="space-y-0.5 text-xs">
      <p className="font-medium">
        {day.status === 'holiday' && day.holiday_name
          ? day.holiday_name
          : day.status === 'on_leave' && day.leave_name
            ? day.leave_name
            : attendanceStatusLabel[day.status]}
      </p>
      {times && (
        <p className="tabular-nums">
          {formatTime(day.first_in_at, local)}–{formatTime(day.last_out_at, local)}
        </p>
      )}
      {day.worked_minutes > 0 && <p className="text-muted-foreground tabular-nums">{formatMinutes(day.worked_minutes)}</p>}
    </div>
  )
}

interface AttendanceCalendarProps {
  /** Every date of the month, in order. */
  dates: string[]
  days: AttendanceDay[] | undefined
  loading: boolean
  weekStart: number
  today: string
  prefs: DisplayPreferences
  onOpen: (day: AttendanceDay) => void
}

/**
 * A month of attendance: a 7-column grid from `sm` up, a list on phones
 * (employee views must work on mobile). Days without a record yet (future,
 * or not evaluated) stay blank.
 */
export function AttendanceCalendar({ dates, days, loading, weekStart, today, prefs, onOpen }: AttendanceCalendarProps) {
  if (loading || !days) {
    return <Skeleton className="h-96 w-full" />
  }

  const byDate = new Map(days.map((d) => [d.work_date, d]))
  const first = dates[0] ?? today
  const leading = (new Date(`${first}T12:00:00Z`).getUTCDay() - weekStart + 7) % 7
  const headerStart = weekStartOf(first, weekStart)

  const cell = (date: string, className: string, mobile: boolean) => {
    const day = byDate.get(date)
    const label = `${date}: ${day ? attendanceStatusLabel[day.status] : 'no record'}`
    const number = (
      <p
        className={cn(
          'text-xs font-medium tabular-nums',
          !mobile && 'mb-1',
          date === today && 'bg-primary text-primary-foreground inline-flex size-5 items-center justify-center rounded-full',
        )}
      >
        {dayOfMonth(date)}
      </p>
    )

    return day ? (
      <button
        type="button"
        aria-label={label}
        onClick={() => onOpen(day)}
        className={cn(
          'hover:ring-ring/40 focus-visible:ring-ring w-full text-left hover:ring-2 hover:ring-inset focus-visible:ring-2 focus-visible:outline-none focus-visible:ring-inset',
          className,
          attendanceCellClass[day.status],
        )}
      >
        {mobile ? (
          <div className="flex gap-3">
            <div className="w-12 shrink-0">
              <p className="text-xs">{weekdayLabel(date)}</p>
              {number}
            </div>
            <DaySummary day={day} prefs={prefs} />
          </div>
        ) : (
          <>
            {number}
            <DaySummary day={day} prefs={prefs} />
          </>
        )}
      </button>
    ) : (
      <div aria-label={label} className={cn('text-muted-foreground', className)}>
        {mobile ? (
          <div className="flex gap-3">
            <div className="w-12 shrink-0">
              <p className="text-xs">{weekdayLabel(date)}</p>
              {number}
            </div>
            <p className="text-xs">—</p>
          </div>
        ) : (
          number
        )}
      </div>
    )
  }

  return (
    <>
      <div className="hidden overflow-hidden rounded-lg border sm:block">
        <div className="bg-muted/40 text-muted-foreground grid grid-cols-7 border-b text-xs font-medium">
          {Array.from({ length: 7 }, (_, i) => (
            <div key={i} className="px-2 py-1.5">
              {weekdayLabel(addDays(headerStart, i))}
            </div>
          ))}
        </div>
        <ol className="grid grid-cols-7">
          {Array.from({ length: leading }, (_, i) => (
            <li key={`blank-${String(i)}`} className="bg-muted/20 min-h-24 border-r border-b" aria-hidden />
          ))}
          {dates.map((date) => (
            <li key={date} className="border-r border-b">
              {cell(date, 'block min-h-24 p-1.5', false)}
            </li>
          ))}
        </ol>
      </div>

      <ol className="divide-y rounded-lg border sm:hidden">
        {dates.map((date) => (
          <li key={date}>{cell(date, 'block px-3 py-2', true)}</li>
        ))}
      </ol>
    </>
  )
}
