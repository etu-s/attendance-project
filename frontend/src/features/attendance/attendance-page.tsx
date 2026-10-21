import { useState } from 'react'
import { RotateCw } from 'lucide-react'
import { toast } from 'sonner'
import { DataTable } from '@/components/data-table'
import { PageHeader } from '@/components/states'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { addDays } from '@/features/scheduling/dates'
import { useCan, useSession } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { todayIn } from '@/lib/format'
import { errorMessage } from '@/lib/forms'
import { useAttendance, useRecalculate, type AttendanceDay } from './api'
import { useAttendanceColumns } from './attendance-columns'
import { AttendanceDaySheet } from './attendance-day-sheet'
import { AttendanceFilters } from './attendance-filters'

/** Attendance over a date range (last 7 days by default). */
export function AttendancePage() {
  const can = useCan()
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  const list = useListParams({
    defaultSort: '-work_date',
    filters: ['from', 'to', 'status', 'department_id'],
    defaultPerPage: 25,
  })
  const from = list.filter('from') || addDays(today, -6)
  const to = list.filter('to') || today
  const attendance = useAttendance({ ...list.params, from, to })
  const recalculate = useRecalculate()
  const [open, setOpen] = useState<AttendanceDay | null>(null)
  const columns = useAttendanceColumns(setOpen, { showDate: true })

  return (
    <>
      <PageHeader
        title="Attendance"
        description="Calculated from punches, corrections and each employee's schedule."
        actions={
          can('attendance.manage') && (
            <Button
              variant="outline"
              disabled={recalculate.isPending}
              onClick={() =>
                recalculate.mutate(
                  { from, to },
                  {
                    onSuccess: () => toast.success('Recalculation queued. Results update in a moment.'),
                    onError: (e) => toast.error(errorMessage(e)),
                  },
                )
              }
            >
              <RotateCw className={recalculate.isPending ? 'animate-spin' : undefined} /> Recalculate range
            </Button>
          )
        }
      />
      <div className="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end">
        <div className="grid gap-1.5">
          <Label htmlFor="attendance-from">From</Label>
          <Input
            id="attendance-from"
            type="date"
            className="w-44"
            value={from}
            max={to}
            onChange={(e) => list.setFilter('from', e.target.value)}
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="attendance-to">To</Label>
          <Input
            id="attendance-to"
            type="date"
            className="w-44"
            value={to}
            min={from}
            max={today}
            onChange={(e) => list.setFilter('to', e.target.value)}
          />
        </div>
        <AttendanceFilters list={list} />
      </div>
      <DataTable
        caption="Attendance"
        columns={columns}
        list={list}
        query={attendance}
        rowKey={(d) => d.id}
        onRowClick={setOpen}
        emptyTitle="No attendance in this range"
        emptyDescription="Days appear once punches are processed or the scheduled job has evaluated them."
      />
      <AttendanceDaySheet dayId={open?.id ?? null} employeeName={open?.employee?.full_name} onClose={() => setOpen(null)} />
    </>
  )
}
