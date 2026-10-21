import { useState } from 'react'
import { EmptyState, ErrorState, PageHeader } from '@/components/states'
import { useDisplayPreferences, useSession } from '@/features/tenant-auth/hooks'
import { todayIn } from '@/lib/format'
import { useMySchedule } from './api'
import { monthEnd, monthStart } from './dates'
import { MonthNav, ScheduleCalendar } from './schedule-calendar'

/** The signed-in employee's own month (list layout on phones). */
export function MySchedulePage() {
  const prefs = useDisplayPreferences()
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  const [month, setMonth] = useState(monthStart(today))
  const schedule = useMySchedule(month, monthEnd(month))

  return (
    <>
      <PageHeader title="My schedule" description="When you are expected to work. Times are in your location's timezone." />
      <MonthNav month={month} onChange={setMonth} today={today} />
      {schedule.isError ? (
        <ErrorState error={schedule.error} onRetry={() => schedule.refetch()} />
      ) : schedule.data === null ? (
        <EmptyState
          title="No employee record linked"
          description="Your login is not linked to an employee record yet. Ask HR to link it."
        />
      ) : (
        <ScheduleCalendar
          days={schedule.data}
          loading={schedule.isPending}
          weekStart={workspace.week_start}
          today={today}
          prefs={prefs}
        />
      )}
    </>
  )
}
