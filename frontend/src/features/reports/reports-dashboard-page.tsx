import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { EmptyState, ErrorState, PageHeader } from '@/components/states'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { attendanceStatusLabel, formatMinutes } from '@/features/attendance/status'
import { formatDuration } from '@/features/overtime/format'
import { useListParams } from '@/hooks/use-list-params'
import { cn } from '@/lib/utils'
import { useDashboard, type Dashboard } from './api'
import { formatCount, formatRate } from './format'
import { ReportFilters } from './report-filters'
import { reportFilterNames, useReportRange } from './report-range'
import { TrendChart } from './trend-chart'

function Tile({ label, value, detail }: { label: string; value: string; detail?: ReactNode }) {
  return (
    <Card className="gap-1 py-4">
      <CardContent className="space-y-1 px-4">
        <p className="text-muted-foreground text-sm">{label}</p>
        <p className="text-2xl font-semibold tracking-tight tabular-nums">{value}</p>
        {detail && <p className="text-muted-foreground text-xs">{detail}</p>}
      </CardContent>
    </Card>
  )
}

const mixOrder = [
  ['present', 'bg-status-present'],
  ['late', 'bg-status-late'],
  ['early_leave', 'bg-status-late'],
  ['late_early', 'bg-status-late'],
  ['incomplete', 'bg-status-late'],
  ['absent', 'bg-status-absent'],
  ['on_leave', 'bg-status-leave'],
  ['holiday', 'bg-status-holiday'],
  ['rest', 'bg-muted-foreground/40'],
  ['day_off', 'bg-muted-foreground/40'],
  ['unscheduled', 'bg-muted-foreground/40'],
  ['in_progress', 'bg-muted-foreground/40'],
] as const

/** Days by status as labelled bars: the label names each, so colour only echoes the status palette. */
function StatusMix({ statuses }: { statuses: Dashboard['statuses'] }) {
  const max = Math.max(1, ...Object.values(statuses))
  const rows = mixOrder.filter(([status]) => statuses[status] > 0)

  if (rows.length === 0) return <p className="text-muted-foreground text-sm">No days in this range.</p>

  return (
    <ul className="space-y-2.5">
      {rows.map(([status, color]) => (
        <li key={status} className="grid grid-cols-[7.5rem_1fr_auto] items-center gap-3 text-sm">
          <span className="truncate">{attendanceStatusLabel[status]}</span>
          <span className="bg-muted h-2 overflow-hidden rounded-full" aria-hidden>
            <span
              className={cn('block h-full rounded-full', color)}
              style={{ width: `${String((statuses[status] / max) * 100)}%` }}
            />
          </span>
          <span className="text-muted-foreground w-12 text-right tabular-nums">{formatCount(statuses[status])}</span>
        </li>
      ))}
    </ul>
  )
}

function RateMeter({ rate }: { rate: number | null }) {
  return (
    <div className="flex items-center justify-end gap-2">
      <span className="bg-status-present/15 hidden h-1.5 w-16 overflow-hidden rounded-full sm:block" aria-hidden>
        <span className="bg-status-present block h-full rounded-full" style={{ width: `${String(rate ?? 0)}%` }} />
      </span>
      <span className="w-14 text-right tabular-nums">{formatRate(rate)}</span>
    </div>
  )
}

function DashboardSkeleton() {
  return (
    <div className="space-y-6" role="status" aria-label="Loading">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
        {Array.from({ length: 6 }, (_, i) => (
          <Skeleton key={i} className="h-24" />
        ))}
      </div>
      <Skeleton className="h-80" />
      <div className="grid gap-6 lg:grid-cols-2">
        <Skeleton className="h-64" />
        <Skeleton className="h-64" />
      </div>
    </div>
  )
}

/**
 * Analytics dashboard: attendance and punctuality rates, absences, lateness,
 * leave and overtime over a range, a daily trend, the status mix and the
 * department comparison. HR sees everyone; managers see their own people.
 */
export function ReportsDashboardPage() {
  const list = useListParams({ defaultSort: 'date', filters: reportFilterNames })
  const range = useReportRange(list, 'last30')
  const dashboard = useDashboard(range.params)
  const data = range.error ? undefined : dashboard.data
  const k = data?.kpis

  return (
    <>
      <PageHeader
        title="Reports"
        description="Attendance rate counts working days only: leave, holidays and rest days are left out."
      />
      <ReportFilters list={list} range={range} search={false} />

      {range.error ? null : dashboard.isError ? (
        <ErrorState error={dashboard.error} onRetry={() => dashboard.refetch()} />
      ) : !data || !k ? (
        <DashboardSkeleton />
      ) : k.working_days === 0 && k.leave_days === 0 && k.rest_days === 0 && k.holiday_days === 0 ? (
        <EmptyState
          title="No attendance in this range"
          description="Calculated attendance appears here once punches have been processed."
        />
      ) : (
        <div className={cn('space-y-6', dashboard.isPlaceholderData && 'opacity-60 transition-opacity')}>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
            <Tile
              label="Attendance rate"
              value={formatRate(k.attendance_rate)}
              detail={`${formatCount(k.attended_days)} of ${formatCount(k.working_days)} working days`}
            />
            <Tile label="Punctuality" value={formatRate(k.punctuality_rate)} detail={`${formatCount(k.late_days)} late days`} />
            <Tile
              label="Absences"
              value={formatCount(k.absent_days)}
              detail={`${formatCount(k.incomplete_days)} with a missing punch`}
            />
            <Tile
              label="Time late"
              value={formatMinutes(k.late_minutes)}
              detail={`Left early: ${formatMinutes(k.early_leave_minutes)}`}
            />
            <Tile
              label="Leave days"
              value={formatCount(k.leave_days)}
              detail={
                k.half_day_leave_days > 0 ? `+ ${formatCount(k.half_day_leave_days)} half days` : 'Full days of approved leave'
              }
            />
            <Tile
              label="Overtime approved"
              value={formatDuration(k.overtime_approved_minutes)}
              detail={
                k.overtime_pending_count > 0 ? (
                  <Link to="/overtime?status=pending" className="underline-offset-4 hover:underline">
                    {formatDuration(k.overtime_pending_minutes)} pending
                  </Link>
                ) : (
                  'Nothing pending'
                )
              }
            />
          </div>

          <Card>
            <CardHeader>
              <CardTitle>Employees per day</CardTitle>
              <CardDescription>Working-day outcomes; rest days and holidays are not shown.</CardDescription>
            </CardHeader>
            <CardContent>
              <TrendChart trend={data.trend} />
            </CardContent>
          </Card>

          <div className="grid gap-6 lg:grid-cols-[1fr_1.4fr]">
            <Card>
              <CardHeader>
                <CardTitle>Days by status</CardTitle>
                <CardDescription>Every calculated day in the range.</CardDescription>
              </CardHeader>
              <CardContent>
                <StatusMix statuses={data.statuses} />
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle>By department</CardTitle>
                <CardDescription>The department recorded on each day; sub-departments are listed on their own.</CardDescription>
              </CardHeader>
              <CardContent>
                {data.departments.length === 0 ? (
                  <p className="text-muted-foreground text-sm">No departments in this range.</p>
                ) : (
                  <div className="overflow-x-auto">
                    <Table>
                      <caption className="sr-only">Attendance by department</caption>
                      <TableHeader>
                        <TableRow>
                          <TableHead>Department</TableHead>
                          <TableHead className="text-right">Attendance</TableHead>
                          <TableHead className="hidden text-right sm:table-cell">Punctuality</TableHead>
                          <TableHead className="text-right">Absent</TableHead>
                          <TableHead className="hidden text-right md:table-cell">Late</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {data.departments.map((d) => (
                          <TableRow key={d.department?.id ?? 0}>
                            <TableCell className="font-medium">{d.department?.name ?? 'No department'}</TableCell>
                            <TableCell>
                              <RateMeter rate={d.attendance_rate} />
                            </TableCell>
                            <TableCell className="hidden text-right tabular-nums sm:table-cell">
                              {formatRate(d.punctuality_rate)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">{formatCount(d.absent_days)}</TableCell>
                            <TableCell className="hidden text-right tabular-nums md:table-cell">
                              {formatCount(d.late_days)}
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
          <p className="text-muted-foreground text-xs">
            {data.source === 'rollup' ? 'From the daily summary.' : 'Calculated live for the people you can see.'} Numbers can be
            up to a minute old.
          </p>
        </div>
      )}
    </>
  )
}
