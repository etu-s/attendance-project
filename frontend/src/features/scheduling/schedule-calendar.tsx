import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import type { DisplayPreferences } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { ResolvedDay } from './api'
import { DayContent } from './day-cell'
import { dayCellClass, dayDescription } from './day-status'
import { addDays, addMonths, dayOfMonth, monthLabel, weekdayLabel, weekStartOf } from './dates'

export function MonthNav({ month, onChange, today }: { month: string; onChange: (month: string) => void; today: string }) {
  return (
    <div className="flex items-center gap-1">
      <Button
        variant="outline"
        size="icon"
        className="size-8"
        aria-label="Previous month"
        onClick={() => onChange(addMonths(month, -1))}
      >
        <ChevronLeft />
      </Button>
      <p className="min-w-36 text-center text-sm font-medium" aria-live="polite">
        {monthLabel(month)}
      </p>
      <Button
        variant="outline"
        size="icon"
        className="size-8"
        aria-label="Next month"
        onClick={() => onChange(addMonths(month, 1))}
      >
        <ChevronRight />
      </Button>
      <Button variant="ghost" size="sm" onClick={() => onChange(`${today.slice(0, 7)}-01`)}>
        Today
      </Button>
    </div>
  )
}

interface ScheduleCalendarProps {
  days: ResolvedDay[] | undefined
  loading: boolean
  weekStart: number
  today: string
  prefs: DisplayPreferences
}

/**
 * A month of resolved days: a 7-column grid from `sm` up, a plain list on
 * phones (employee views must work on mobile).
 */
export function ScheduleCalendar({ days, loading, weekStart, today, prefs }: ScheduleCalendarProps) {
  if (loading || !days) {
    return <Skeleton className="h-96 w-full" />
  }

  const first = days[0]?.date ?? today
  const leading = (new Date(`${first}T12:00:00Z`).getUTCDay() - weekStart + 7) % 7
  const headerStart = weekStartOf(first, weekStart)

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
          {days.map((day) => (
            <li
              key={day.date}
              className={cn('min-h-24 border-r border-b p-1.5', dayCellClass[day.status])}
              aria-label={`${day.date}: ${dayDescription(day, prefs)}`}
            >
              <p
                className={cn(
                  'mb-1 text-xs font-medium tabular-nums',
                  day.date === today &&
                    'bg-primary text-primary-foreground inline-flex size-5 items-center justify-center rounded-full',
                )}
              >
                {dayOfMonth(day.date)}
              </p>
              <DayContent day={day} prefs={prefs} />
            </li>
          ))}
        </ol>
      </div>

      <ol className="divide-y rounded-lg border sm:hidden">
        {days.map((day) => (
          <li key={day.date} className={cn('flex gap-3 px-3 py-2', dayCellClass[day.status])}>
            <div className={cn('w-12 shrink-0 text-xs', day.date === today && 'text-primary font-semibold')}>
              <p>{weekdayLabel(day.date)}</p>
              <p className="text-base font-medium tabular-nums">{dayOfMonth(day.date)}</p>
            </div>
            <div className="min-w-0 flex-1 pt-0.5">
              <DayContent day={day} prefs={prefs} showSource />
            </div>
          </li>
        ))}
      </ol>
    </>
  )
}
