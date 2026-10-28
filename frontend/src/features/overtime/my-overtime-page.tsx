import { useState } from 'react'
import { EmptyState, PageHeader } from '@/components/states'
import { monthEnd, monthStart } from '@/features/scheduling/dates'
import { MonthNav } from '@/features/scheduling/schedule-calendar'
import { useSession } from '@/features/tenant-auth/hooks'
import { todayIn } from '@/lib/format'
import { useMyOvertime } from './api'
import { OvertimeList } from './overtime-list'

/** The signed-in employee's own overtime (read-only). */
export function MyOvertimePage() {
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  const [month, setMonth] = useState(monthStart(today))
  const overtime = useMyOvertime(month, monthEnd(month))

  return (
    <>
      <PageHeader title="My overtime" description="Calculated from your attendance and approved by your manager or HR." />
      <MonthNav month={month} onChange={setMonth} today={today} />
      {overtime.data === null ? (
        <EmptyState
          title="No employee record linked"
          description="Your login is not linked to an employee record yet. Ask HR to link it."
        />
      ) : (
        <OvertimeList
          records={overtime.data}
          loading={overtime.isPending}
          error={overtime.isError ? overtime.error : null}
          onRetry={() => overtime.refetch()}
        />
      )}
    </>
  )
}
