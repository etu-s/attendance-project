import { useState } from 'react'
import { EmptyState, ErrorState, PageHeader } from '@/components/states'
import { monthEnd, monthStart } from '@/features/scheduling/dates'
import { MonthNav } from '@/features/scheduling/schedule-calendar'
import { useDisplayPreferences, useSession } from '@/features/tenant-auth/hooks'
import { todayIn } from '@/lib/format'
import { useMyAttendance, type AttendanceDay } from './api'
import { AttendanceCalendar } from './attendance-calendar'
import { AttendanceDaySheet } from './attendance-day-sheet'
import { datesBetween } from './time'

/** The signed-in employee's own attendance; tap a day to see punches or ask for a correction. */
export function MyAttendancePage() {
  const prefs = useDisplayPreferences()
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  const [month, setMonth] = useState(monthStart(today))
  const attendance = useMyAttendance(month, monthEnd(month))
  const [open, setOpen] = useState<AttendanceDay | null>(null)

  return (
    <>
      <PageHeader
        title="My attendance"
        description="Your punches and how they were counted. Missed a punch? Open the day to ask for a correction."
      />
      <MonthNav month={month} onChange={setMonth} today={today} />
      {attendance.isError ? (
        <ErrorState error={attendance.error} onRetry={() => attendance.refetch()} />
      ) : attendance.data === null ? (
        <EmptyState
          title="No employee record linked"
          description="Your login is not linked to an employee record yet. Ask HR to link it."
        />
      ) : (
        <AttendanceCalendar
          dates={datesBetween(month, monthEnd(month))}
          days={attendance.data}
          loading={attendance.isPending}
          weekStart={workspace.week_start}
          today={today}
          prefs={prefs}
          onOpen={setOpen}
        />
      )}
      <AttendanceDaySheet dayId={open?.id ?? null} onClose={() => setOpen(null)} />
    </>
  )
}
