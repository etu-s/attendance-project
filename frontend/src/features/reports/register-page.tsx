import { LockedPeriodsNote } from '@/features/payroll/locked-periods-note'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { EmptyState, ErrorState, PageHeader } from '@/components/states'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { attendanceCellClass, attendanceStatusLabel, formatMinutes } from '@/features/attendance/status'
import { addMonths, dayOfMonth, monthLabel, weekdayLabel } from '@/features/scheduling/dates'
import { useSession } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import type { AttendanceStatus } from '@/features/attendance/api'
import { todayIn } from '@/lib/format'
import { cn } from '@/lib/utils'
import { useReport, type RegisterCell, type RegisterMeta, type RegisterRow } from './api'
import { EmployeeCell } from './employee-cell'
import { ExportMenu } from './export-menu'
import { ReportFilters } from './report-filters'

function cellTitle(date: string, cell: RegisterCell): string {
  const parts = [`${date}: ${attendanceStatusLabel[cell.status]}`]
  if (cell.half_day_leave) parts.push('half day of leave')
  if (cell.late_minutes > 0) parts.push(`late ${formatMinutes(cell.late_minutes)}`)
  if (cell.early_leave_minutes > 0) parts.push(`left early ${formatMinutes(cell.early_leave_minutes)}`)
  if (cell.worked_minutes > 0) parts.push(`worked ${formatMinutes(cell.worked_minutes)}`)

  return parts.join(', ')
}

const legend: AttendanceStatus[] = [
  'present',
  'late',
  'early_leave',
  'incomplete',
  'absent',
  'on_leave',
  'holiday',
  'rest',
  'day_off',
]

/**
 * The monthly register: employees × days, one status code per cell (the
 * code is the identity; the tint echoes the status colours), totals per row.
 */
export function RegisterPage() {
  const { workspace } = useSession()
  const list = useListParams({
    defaultSort: 'name',
    filters: ['month', 'department_id', 'location_id', 'group_id'],
    defaultPerPage: 25,
  })
  const month = list.filter('month') || todayIn(workspace.timezone).slice(0, 7)
  const params = { ...list.params, month }
  const report = useReport<RegisterRow, RegisterMeta>('register', params)
  const meta = report.data?.meta
  const rows = report.data?.data ?? []
  const exportParams = {
    month,
    department_id: list.filter('department_id') || undefined,
    location_id: list.filter('location_id') || undefined,
    group_id: list.filter('group_id') || undefined,
    search: list.search || undefined,
  }

  return (
    <>
      <PageHeader
        title="Monthly register"
        description="Each employee's status on every day of the month."
        actions={<ExportMenu report="register" params={exportParams} />}
      />
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div className="flex items-center gap-1">
          <Button
            variant="outline"
            size="icon"
            aria-label="Previous month"
            onClick={() => list.setFilter('month', addMonths(month, -1).slice(0, 7))}
          >
            <ChevronLeft />
          </Button>
          <Button
            variant="outline"
            size="icon"
            aria-label="Next month"
            onClick={() => list.setFilter('month', addMonths(month, 1).slice(0, 7))}
          >
            <ChevronRight />
          </Button>
        </div>
        <ReportFilters list={list} month={{ value: month, onChange: (v) => list.setFilter('month', v) }} />
      </div>
      <LockedPeriodsNote periods={report.data?.meta.locked_periods} />

      <ul className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-xs" aria-label="Codes">
        {legend.map((s) => (
          <li key={s} className="flex items-center gap-1.5">
            <span
              className={cn(
                'inline-flex h-5 min-w-6 items-center justify-center rounded border px-1 font-mono text-[11px]',
                attendanceCellClass[s],
              )}
            >
              {meta?.codes[s] ?? ''}
            </span>
            {attendanceStatusLabel[s]}
          </li>
        ))}
        <li className="flex items-center gap-1.5">
          <span className="font-mono">½</span> half day of leave
        </li>
      </ul>

      {report.isError ? (
        <ErrorState error={report.error} onRetry={() => report.refetch()} />
      ) : report.isPending || !meta ? (
        <Skeleton className="h-96" role="status" aria-label="Loading" />
      ) : rows.length === 0 ? (
        <EmptyState
          title={`No attendance in ${monthLabel(month)}`}
          description="Employees with calculated attendance this month appear here."
        />
      ) : (
        <div className="space-y-3">
          <div className={cn('overflow-x-auto rounded-lg border', report.isPlaceholderData && 'opacity-60 transition-opacity')}>
            <table className="w-full border-collapse text-xs">
              <caption className="sr-only">Monthly register for {monthLabel(month)}</caption>
              <thead>
                <tr className="bg-muted/40">
                  <th scope="col" className="bg-muted/40 sticky left-0 z-10 min-w-44 px-3 py-2 text-left font-medium">
                    Employee
                  </th>
                  {meta.dates.map((d) => (
                    <th key={d} scope="col" className="min-w-8 px-0.5 py-1 text-center font-normal">
                      <span className="text-muted-foreground block text-[10px]">{weekdayLabel(d, 'narrow')}</span>
                      {dayOfMonth(d)}
                    </th>
                  ))}
                  <th scope="col" className="px-2 py-2 text-right font-medium">
                    Attended
                  </th>
                  <th scope="col" className="px-2 py-2 text-right font-medium">
                    Absent
                  </th>
                  <th scope="col" className="px-2 py-2 text-right font-medium">
                    Late
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.employee?.id ?? 0} className="border-t">
                    <th scope="row" className="bg-card sticky left-0 z-10 px-3 py-1.5 text-left font-normal">
                      <EmployeeCell employee={row.employee} />
                    </th>
                    {meta.dates.map((d) => {
                      const cell = row.days[d]

                      return (
                        <td key={d} className="p-0.5 text-center">
                          {cell && cell.status !== 'not_employed' ? (
                            <span
                              title={cellTitle(d, cell)}
                              className={cn(
                                'inline-flex h-6 w-full min-w-7 items-center justify-center rounded font-mono text-[11px]',
                                attendanceCellClass[cell.status],
                              )}
                            >
                              {meta.codes[cell.status]}
                              {cell.half_day_leave && cell.status !== 'on_leave' ? '½' : ''}
                              <span className="sr-only"> {cellTitle(d, cell)}</span>
                            </span>
                          ) : (
                            <span className="text-muted-foreground/50" aria-label={`${d}: no record`}>
                              ·
                            </span>
                          )}
                        </td>
                      )
                    })}
                    <td className="px-2 text-right tabular-nums">{row.totals.attended_days}</td>
                    <td className="px-2 text-right tabular-nums">{row.totals.absent_days}</td>
                    <td className="px-2 text-right tabular-nums">{row.totals.late_days}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="text-muted-foreground flex items-center justify-between text-sm">
            <span>
              {report.data.meta.total} employee{report.data.meta.total === 1 ? '' : 's'}
            </span>
            {report.data.meta.last_page > 1 && (
              <div className="flex items-center gap-2">
                <Button variant="outline" size="sm" disabled={list.page <= 1} onClick={() => list.setPage(list.page - 1)}>
                  Previous
                </Button>
                <span>
                  Page {list.page} of {report.data.meta.last_page}
                </span>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={list.page >= report.data.meta.last_page}
                  onClick={() => list.setPage(list.page + 1)}
                >
                  Next
                </Button>
              </div>
            )}
          </div>
        </div>
      )}
    </>
  )
}
