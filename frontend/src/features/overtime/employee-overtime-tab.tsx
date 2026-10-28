import { useState } from 'react'
import { Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { monthEnd, monthStart } from '@/features/scheduling/dates'
import { MonthNav } from '@/features/scheduling/schedule-calendar'
import { useCan, useSession } from '@/features/tenant-auth/hooks'
import { todayIn } from '@/lib/format'
import { useEmployeeOvertime } from './api'
import { ManualEntryDialog } from './overtime-dialogs'
import { OvertimeList } from './overtime-list'

/** "Overtime" tab of an employee profile. */
export function EmployeeOvertimeTab({ employeeId }: { employeeId: number }) {
  const can = useCan()
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  const [month, setMonth] = useState(monthStart(today))
  const overtime = useEmployeeOvertime(employeeId, month, monthEnd(month))
  const [recording, setRecording] = useState(false)

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <MonthNav month={month} onChange={setMonth} today={today} />
        {can('overtime.manage') && (
          <Button size="sm" variant="outline" onClick={() => setRecording(true)}>
            <Plus /> Record overtime
          </Button>
        )}
      </div>
      <OvertimeList
        records={overtime.data}
        loading={overtime.isPending}
        error={overtime.isError ? overtime.error : null}
        onRetry={() => overtime.refetch()}
      />
      <ManualEntryDialog open={recording} employeeId={employeeId} editing={null} onClose={() => setRecording(false)} />
    </div>
  )
}
