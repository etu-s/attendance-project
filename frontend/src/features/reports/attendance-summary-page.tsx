import { DataTable, type Column } from '@/components/data-table'
import { PageHeader } from '@/components/states'
import { formatMinutes } from '@/features/attendance/status'
import { useListParams } from '@/hooks/use-list-params'
import { useReport, type SummaryRow } from './api'
import { EmployeeCell } from './employee-cell'
import { ExportMenu } from './export-menu'
import { formatRate } from './format'
import { ReportFilters } from './report-filters'
import { reportFilterNames, useReportRange } from './report-range'

const num = (value: number) => <span className="tabular-nums">{value}</span>

/** Per employee over a range: working days, outcomes, minutes and rates. */
export function AttendanceSummaryPage() {
  const list = useListParams({ defaultSort: 'name', filters: reportFilterNames, defaultPerPage: 25 })
  const range = useReportRange(list)
  const params = { ...list.params, ...range.params }
  const report = useReport<SummaryRow>('attendance-summary', params, range.error === null)

  const columns: Column<SummaryRow>[] = [
    { key: 'employee', header: 'Employee', sortKey: 'name', cell: (r) => <EmployeeCell employee={r.employee} /> },
    {
      key: 'rate',
      header: 'Attendance',
      cell: (r) => (
        <div className="tabular-nums">
          <p className="font-medium">{formatRate(r.attendance_rate)}</p>
          <p className="text-muted-foreground text-xs">
            {r.attended_days} / {r.working_days} days
          </p>
        </div>
      ),
    },
    { key: 'absent', header: 'Absent', sortKey: 'absent_days', cell: (r) => num(r.absent_days) },
    { key: 'late', header: 'Late days', sortKey: 'late_days', cell: (r) => num(r.late_days) },
    {
      key: 'late_minutes',
      header: 'Time late',
      sortKey: 'late_minutes',
      hideBelow: 'md',
      cell: (r) => <span className="tabular-nums">{formatMinutes(r.late_minutes)}</span>,
    },
    {
      key: 'early',
      header: 'Left early',
      sortKey: 'early_leave_minutes',
      hideBelow: 'lg',
      cell: (r) => <span className="tabular-nums">{formatMinutes(r.early_leave_minutes)}</span>,
    },
    { key: 'missing', header: 'Missing punch', hideBelow: 'lg', cell: (r) => num(r.incomplete_days) },
    { key: 'leave', header: 'Leave', hideBelow: 'md', cell: (r) => num(r.leave_days) },
    {
      key: 'worked',
      header: 'Worked',
      sortKey: 'worked_minutes',
      hideBelow: 'sm',
      cell: (r) => <span className="tabular-nums">{formatMinutes(r.worked_minutes)}</span>,
    },
    {
      key: 'punctuality',
      header: 'Punctuality',
      hideBelow: 'md',
      cell: (r) => <span className="tabular-nums">{formatRate(r.punctuality_rate)}</span>,
    },
  ]

  return (
    <>
      <PageHeader
        title="Attendance summary"
        description="One row per employee with calculated attendance in the range."
        actions={
          <ExportMenu
            report="attendance-summary"
            params={{ ...range.params, search: list.search || undefined, sort: list.sort }}
            disabled={range.error !== null}
          />
        }
      />
      <ReportFilters list={list} range={range} />
      {range.error === null && (
        <DataTable
          caption="Attendance summary per employee"
          columns={columns}
          list={list}
          query={report}
          rowKey={(r) => r.employee?.id ?? 0}
          emptyTitle="No attendance in this range"
          emptyDescription="Employees with calculated attendance appear here."
        />
      )}
    </>
  )
}
