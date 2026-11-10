import { DataTable, type Column } from '@/components/data-table'
import { PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { attendanceStatusLabel, attendanceStatusTone, formatMinutes } from '@/features/attendance/status'
import { useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { formatLocalDate, formatTime } from '@/lib/format'
import { useReport, type ExceptionRow, type ExceptionType } from './api'
import { EmployeeCell } from './employee-cell'
import { ExportMenu } from './export-menu'
import { ReportFilters } from './report-filters'
import { reportFilterNames, useReportRange } from './report-range'

const lateType = { value: 'late', label: 'Late', empty: 'Nobody arrived late in this range.' } as const

const types: { value: ExceptionType; label: string; empty: string }[] = [
  lateType,
  { value: 'early_leave', label: 'Left early', empty: 'Nobody left early in this range.' },
  { value: 'absent', label: 'Absent', empty: 'No absences in this range.' },
  { value: 'incomplete', label: 'Missing punch', empty: 'No days with a missing punch in this range.' },
]

/** Days that went wrong: late arrivals, early departures, absences and missing punches. */
export function ExceptionsPage() {
  const prefs = useDisplayPreferences()
  const list = useListParams({ defaultSort: '-work_date', filters: [...reportFilterNames, 'type'], defaultPerPage: 25 })
  const range = useReportRange(list)
  const type = (list.filter('type') || 'late') as ExceptionType
  const report = useReport<ExceptionRow>('exceptions', { ...list.params, ...range.params, type }, range.error === null)
  const current = types.find((t) => t.value === type) ?? lateType

  // Punch times in the day's own timezone, as the employee experienced them.
  const time = (iso: string | null, timezone: string) => (iso ? formatTime(iso, { ...prefs, timezone }) : '—')

  const columns: Column<ExceptionRow>[] = [
    { key: 'date', header: 'Date', sortKey: 'work_date', cell: (r) => formatLocalDate(r.work_date, prefs) },
    { key: 'employee', header: 'Employee', cell: (r) => <EmployeeCell employee={r.employee} /> },
    { key: 'department', header: 'Department', hideBelow: 'lg', cell: (r) => r.department?.name ?? '—' },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => <StatusBadge tone={attendanceStatusTone[r.status]}>{attendanceStatusLabel[r.status]}</StatusBadge>,
    },
    {
      key: 'in',
      header: 'In',
      hideBelow: 'sm',
      cell: (r) => <span className="tabular-nums">{time(r.first_in_at, r.timezone)}</span>,
    },
    {
      key: 'out',
      header: 'Out',
      hideBelow: 'sm',
      cell: (r) => <span className="tabular-nums">{time(r.last_out_at, r.timezone)}</span>,
    },
    {
      key: 'late',
      header: 'Late',
      sortKey: 'late_minutes',
      cell: (r) => <span className="tabular-nums">{formatMinutes(r.late_minutes)}</span>,
    },
    {
      key: 'early',
      header: 'Left early',
      sortKey: 'early_leave_minutes',
      hideBelow: 'md',
      cell: (r) => <span className="tabular-nums">{formatMinutes(r.early_leave_minutes)}</span>,
    },
    {
      key: 'worked',
      header: 'Worked',
      sortKey: 'worked_minutes',
      hideBelow: 'md',
      cell: (r) => <span className="tabular-nums">{formatMinutes(r.worked_minutes)}</span>,
    },
  ]

  return (
    <>
      <PageHeader
        title="Late, early and absent"
        description="One row per employee and day."
        actions={
          <ExportMenu
            report="exceptions"
            params={{ ...range.params, type, search: list.search || undefined, sort: list.sort }}
            disabled={range.error !== null}
          />
        }
      />
      <Tabs value={type} onValueChange={(v) => list.setFilter('type', v)}>
        <TabsList>
          {types.map((t) => (
            <TabsTrigger key={t.value} value={t.value}>
              {t.label}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>
      <ReportFilters list={list} range={range} />
      {range.error === null && (
        <DataTable
          caption={`${current.label} days`}
          columns={columns}
          list={list}
          query={report}
          rowKey={(r) => r.id}
          emptyTitle={`No ${current.label.toLowerCase()} days`}
          emptyDescription={current.empty}
        />
      )}
    </>
  )
}
