import { DataTable, type Column } from '@/components/data-table'
import { PageHeader } from '@/components/states'
import { useListParams } from '@/hooks/use-list-params'
import { useReport, type LeaveMeta, type LeaveRow } from './api'
import { EmployeeCell } from './employee-cell'
import { ExportMenu } from './export-menu'
import { formatHalfDays } from './format'
import { ReportFilters } from './report-filters'
import { useReportRange } from './report-range'

/** Approved leave taken in the range, per employee and leave type (working days only). */
export function LeaveReportPage() {
  const list = useListParams({ defaultSort: '-half_days', filters: ['from', 'to', 'department_id'], defaultPerPage: 25 })
  const range = useReportRange(list)
  const report = useReport<LeaveRow, LeaveMeta>('leave', { ...list.params, ...range.params }, range.error === null)
  const leaveTypes = report.data?.meta.leave_types ?? []

  const columns: Column<LeaveRow>[] = [
    { key: 'employee', header: 'Employee', sortKey: 'name', cell: (r) => <EmployeeCell employee={r.employee} /> },
    {
      key: 'total',
      header: 'Total days',
      sortKey: 'half_days',
      cell: (r) => <span className="font-medium tabular-nums">{formatHalfDays(r.half_days)}</span>,
    },
    ...leaveTypes.map<Column<LeaveRow>>((t) => ({
      key: `type-${String(t.id)}`,
      header: t.name,
      hideBelow: 'md',
      cell: (r) => {
        const halves = r.types[String(t.id)] ?? 0

        return halves > 0 ? (
          <span className="tabular-nums">{formatHalfDays(halves)}</span>
        ) : (
          <span className="text-muted-foreground">—</span>
        )
      },
    })),
  ]

  return (
    <>
      <PageHeader
        title="Leave taken"
        description="Approved leave on scheduled working days; holidays and rest days inside a request are free."
        actions={
          <ExportMenu
            report="leave"
            params={{ ...range.params, search: list.search || undefined, sort: list.sort }}
            disabled={range.error !== null}
          />
        }
      />
      <ReportFilters list={list} range={range} location={false} group={false} />
      {range.error === null && (
        <DataTable
          caption="Leave taken per employee"
          columns={columns}
          list={list}
          query={report}
          rowKey={(r) => r.employee?.id ?? 0}
          emptyTitle="No leave taken in this range"
          emptyDescription="Approved leave appears here."
        />
      )}
    </>
  )
}
