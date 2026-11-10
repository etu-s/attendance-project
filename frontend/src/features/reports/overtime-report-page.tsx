import { LockedPeriodsNote } from '@/features/payroll/locked-periods-note'
import { DataTable, type Column } from '@/components/data-table'
import { PageHeader } from '@/components/states'
import { formatDuration } from '@/features/overtime/format'
import { useListParams } from '@/hooks/use-list-params'
import { useReport, type OvertimeRow } from './api'
import { EmployeeCell } from './employee-cell'
import { ExportMenu } from './export-menu'
import { ReportFilters } from './report-filters'
import { useReportRange } from './report-range'

const minutes = (value: number) => <span className="tabular-nums">{formatDuration(value)}</span>

/** Overtime per employee: approved and weighted minutes by kind of day, and what is pending. */
export function OvertimeReportPage() {
  const list = useListParams({
    defaultSort: '-approved_weighted_minutes',
    filters: ['from', 'to', 'department_id'],
    defaultPerPage: 25,
  })
  const range = useReportRange(list)
  const report = useReport<OvertimeRow>('overtime', { ...list.params, ...range.params }, range.error === null)

  const columns: Column<OvertimeRow>[] = [
    { key: 'employee', header: 'Employee', cell: (r) => <EmployeeCell employee={r.employee} /> },
    { key: 'approved', header: 'Approved', cell: (r) => minutes(r.approved_minutes) },
    { key: 'weighted', header: 'Weighted', cell: (r) => minutes(r.approved_weighted_minutes) },
    { key: 'workday', header: 'Working days', hideBelow: 'md', cell: (r) => minutes(r.approved_workday_minutes) },
    { key: 'rest', header: 'Rest days', hideBelow: 'md', cell: (r) => minutes(r.approved_rest_day_minutes) },
    { key: 'holiday', header: 'Holidays', hideBelow: 'lg', cell: (r) => minutes(r.approved_holiday_minutes) },
    {
      key: 'pending',
      header: 'Pending',
      cell: (r) =>
        r.pending_count > 0 ? (
          <span className="tabular-nums">
            {formatDuration(r.pending_minutes)} ({r.pending_count})
          </span>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Overtime"
        description="Weighted = minutes × rate. Pending overtime is not in the approved totals."
        actions={
          <ExportMenu
            report="overtime"
            params={{ ...range.params, search: list.search || undefined }}
            disabled={range.error !== null}
          />
        }
      />
      <ReportFilters list={list} range={range} location={false} group={false} />
      <LockedPeriodsNote periods={report.data?.meta.locked_periods} />
      {range.error === null && (
        <DataTable
          caption="Overtime per employee"
          columns={columns}
          list={list}
          query={report}
          rowKey={(r) => r.employee?.id ?? 0}
          emptyTitle="No overtime in this range"
          emptyDescription="Approved and pending overtime appears here."
        />
      )}
    </>
  )
}
