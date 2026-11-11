import { useCallback, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { DataTable } from '@/components/data-table'
import { PageHeader } from '@/components/states'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { useSession } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { todayIn } from '@/lib/format'
import { resolveAppContext } from '@/lib/host'
import { usePrivateChannel } from '@/lib/realtime'
import { cn } from '@/lib/utils'
import { useAttendance, useAttendanceSummary, type AttendanceDay, type AttendanceStatus } from './api'
import { useAttendanceColumns } from './attendance-columns'
import { AttendanceDaySheet } from './attendance-day-sheet'
import { AttendanceFilters } from './attendance-filters'

/** Summary tiles; each one filters the list below to its statuses. */
const tiles: { label: string; statuses: AttendanceStatus[]; tone: string }[] = [
  { label: 'Present', statuses: ['present'], tone: 'text-status-present' },
  { label: 'Late', statuses: ['late', 'late_early'], tone: 'text-status-late' },
  { label: 'Left early', statuses: ['early_leave'], tone: 'text-status-late' },
  { label: 'Missing punch', statuses: ['incomplete'], tone: 'text-status-late' },
  { label: 'Absent', statuses: ['absent'], tone: 'text-status-absent' },
  { label: 'In progress', statuses: ['in_progress'], tone: 'text-foreground' },
  { label: 'On leave', statuses: ['on_leave'], tone: 'text-status-leave' },
  { label: 'Off / holiday', statuses: ['holiday', 'rest', 'day_off'], tone: 'text-status-holiday' },
]

/**
 * Who is in, late or absent on one day (today by default). Live over Reverb
 * when configured, otherwise refreshed every 30 seconds while open.
 */
export function TodayPage() {
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  const list = useListParams({ defaultSort: 'first_in_at', filters: ['date', 'status', 'department_id'], defaultPerPage: 25 })
  const date = list.filter('date') || today
  const live = date === today
  // Pushed over Reverb when configured; polling stays as the fallback while the socket is down.
  const queryClient = useQueryClient()
  const refresh = useCallback(() => void queryClient.invalidateQueries({ queryKey: ['tenant', 'attendance'] }), [queryClient])
  const context = resolveAppContext()
  const pushed = usePrivateChannel(
    live && context.kind === 'tenant' ? `workspace.${context.subdomain}.attendance` : null,
    'today.changed',
    refresh,
  )
  const poll = live && !pushed
  const summary = useAttendanceSummary({ date, department_id: list.filter('department_id') || undefined }, poll)
  const attendance = useAttendance({ ...list.params, date }, { live: poll })
  const [open, setOpen] = useState<AttendanceDay | null>(null)
  const columns = useAttendanceColumns(setOpen, { showDate: false })
  const activeStatus = list.filter('status')

  return (
    <>
      <PageHeader
        title={live ? 'Today' : 'Attendance on a day'}
        description={
          live
            ? `Live attendance, ${pushed ? 'updated as punches arrive' : 'refreshed every 30 seconds'}. A day becomes absent once its punch window closes.`
            : 'Attendance for the selected day.'
        }
        actions={
          <div className="grid gap-1.5">
            <Label htmlFor="today-date" className="sr-only">
              Date
            </Label>
            <Input
              id="today-date"
              type="date"
              className="w-44"
              value={date}
              max={today}
              onChange={(e) => list.setFilter('date', e.target.value === today ? '' : e.target.value)}
            />
          </div>
        }
      />

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-8">
        {tiles.map((tile) => {
          const value = tile.statuses.join(',')
          const count = tile.statuses.reduce((sum, s) => sum + (summary.data?.counts[s] ?? 0), 0)
          const selected = activeStatus === value

          return (
            <button
              key={tile.label}
              type="button"
              aria-pressed={selected}
              onClick={() => list.setFilter('status', selected ? '' : value)}
              className="focus-visible:ring-ring rounded-xl text-left focus-visible:ring-2 focus-visible:outline-none"
            >
              <Card className={cn('gap-0 py-4 transition-colors', selected && 'border-primary bg-accent')}>
                <CardContent className="space-y-1 px-4">
                  <p className="text-muted-foreground text-xs">{tile.label}</p>
                  {summary.isPending ? (
                    <Skeleton className="h-7 w-10" />
                  ) : (
                    <p className={cn('text-2xl font-semibold tabular-nums', tile.tone)}>{count}</p>
                  )}
                </CardContent>
              </Card>
            </button>
          )
        })}
      </div>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
        <AttendanceFilters list={list} status={false} />
      </div>

      <DataTable
        caption={`Attendance on ${date}`}
        columns={columns}
        list={list}
        query={attendance}
        rowKey={(d) => d.id}
        onRowClick={setOpen}
        emptyTitle="Nothing recorded yet"
        emptyDescription="Days appear as punches arrive, and for everyone once the scheduled job has run."
      />
      <AttendanceDaySheet dayId={open?.id ?? null} employeeName={open?.employee?.full_name} onClose={() => setOpen(null)} />
    </>
  )
}
