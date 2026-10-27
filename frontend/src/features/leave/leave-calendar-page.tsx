import { useState } from 'react'
import { EmptyState, ErrorState, PageHeader } from '@/components/states'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { useDepartments } from '@/features/organization/api'
import { monthEnd, monthStart, weekdayLabel } from '@/features/scheduling/dates'
import { MonthNav } from '@/features/scheduling/schedule-calendar'
import { useDisplayPreferences, useSession } from '@/features/tenant-auth/hooks'
import { formatLocalDate, todayIn } from '@/lib/format'
import { cn } from '@/lib/utils'
import { useLeaveCalendar, type DayPortion, type LeaveRequest } from './api'

const portionLabel: Record<DayPortion, string> = { full: '', first_half: ' (morning)', second_half: ' (afternoon)' }

/**
 * Who is off on which day of a month, for the viewer's people. Pending leave
 * is shown dashed. Only counted (scheduled working) days appear.
 */
export function LeaveCalendarPage() {
  const prefs = useDisplayPreferences()
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  const [month, setMonth] = useState(monthStart(today))
  const [department, setDepartment] = useState('')
  const departments = useDepartments(true)
  const calendar = useLeaveCalendar({ from: month, to: monthEnd(month), department_id: department || undefined })

  const byDate = new Map<string, { request: LeaveRequest; portion: DayPortion }[]>()
  for (const request of calendar.data ?? []) {
    for (const day of request.counted_days ?? []) {
      if (day.date < month || day.date > monthEnd(month)) continue
      byDate.set(day.date, [...(byDate.get(day.date) ?? []), { request, portion: day.portion }])
    }
  }
  const dates = [...byDate.keys()].sort()

  return (
    <>
      <PageHeader
        title="Leave calendar"
        description="Approved and pending leave of your people. Dashed = waiting for approval."
      />
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <MonthNav month={month} onChange={setMonth} today={today} />
        <div className="grid gap-1.5">
          <Label htmlFor="calendar-department">Department</Label>
          <Select value={department || 'all'} onValueChange={(v) => setDepartment(v === 'all' ? '' : v)}>
            <SelectTrigger id="calendar-department" className="w-52">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All departments</SelectItem>
              {(departments.data ?? []).map((d) => (
                <SelectItem key={d.id} value={String(d.id)}>
                  {d.path ?? d.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      {calendar.isError ? (
        <ErrorState error={calendar.error} onRetry={() => calendar.refetch()} />
      ) : calendar.isPending ? (
        <Skeleton className="h-64 w-full" />
      ) : dates.length === 0 ? (
        <EmptyState title="Nobody is off this month" description="Approved and pending leave will show here." />
      ) : (
        <ol className="divide-y rounded-lg border">
          {dates.map((date) => (
            <li key={date} className={cn('flex flex-col gap-2 px-4 py-3 sm:flex-row sm:gap-6', date === today && 'bg-accent/40')}>
              <div className="w-32 shrink-0 text-sm">
                <p className="font-medium">{formatLocalDate(date, prefs)}</p>
                <p className="text-muted-foreground text-xs">{weekdayLabel(date)}</p>
              </div>
              <ul className="flex flex-wrap gap-2">
                {(byDate.get(date) ?? []).map(({ request, portion }) => (
                  <li
                    key={request.id}
                    className={cn(
                      'flex items-center gap-2 rounded-full border px-3 py-1 text-sm',
                      request.status === 'pending' && 'border-dashed',
                    )}
                  >
                    <span
                      className="size-2.5 rounded-full"
                      style={{ backgroundColor: request.type.color ?? 'var(--status-leave)' }}
                      aria-hidden
                    />
                    {request.employee.full_name}
                    <span className="text-muted-foreground text-xs">
                      {request.type.name}
                      {portionLabel[portion]}
                      {request.status === 'pending' && ' · pending'}
                    </span>
                  </li>
                ))}
              </ul>
            </li>
          ))}
        </ol>
      )}
    </>
  )
}
