import type { Column } from '@/components/data-table'
import { StatusBadge } from '@/components/status-badge'
import { useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { formatLocalDate, formatTime } from '@/lib/format'
import type { AttendanceDay } from './api'
import { attendanceStatusLabel, attendanceStatusTone, formatMinutes } from './status'

/**
 * Columns shared by the Today board and the attendance list. The employee
 * name is a button, so the detail opens from the keyboard too.
 */
export function useAttendanceColumns(
  onOpen: (day: AttendanceDay) => void,
  options: { showDate: boolean },
): Column<AttendanceDay>[] {
  const prefs = useDisplayPreferences()
  const local = (day: AttendanceDay) => ({ ...prefs, timezone: day.timezone })

  return [
    ...(options.showDate
      ? [
          {
            key: 'date',
            header: 'Date',
            sortKey: 'work_date',
            cell: (d: AttendanceDay) => <span className="tabular-nums">{formatLocalDate(d.work_date, prefs)}</span>,
          },
        ]
      : []),
    {
      key: 'employee',
      header: 'Employee',
      cell: (d) => (
        <button
          type="button"
          className="focus-visible:ring-ring rounded text-left focus-visible:ring-2 focus-visible:outline-none"
          onClick={(event) => {
            event.stopPropagation()
            onOpen(d)
          }}
        >
          <span className="block font-medium underline-offset-4 hover:underline">{d.employee?.full_name ?? 'Employee'}</span>
          <span className="text-muted-foreground block text-xs">{d.employee?.employee_code}</span>
        </button>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (d) => (
        <div className="flex flex-col items-start gap-1">
          <StatusBadge tone={attendanceStatusTone[d.status]}>{attendanceStatusLabel[d.status]}</StatusBadge>
          {d.holiday_name && <span className="text-status-holiday text-xs">{d.holiday_name}</span>}
          {d.leave_name && (
            <span className="text-status-leave text-xs">
              {d.leave_name}
              {d.leave_portion !== 'full' && ' (half day)'}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'in',
      header: 'In',
      sortKey: 'first_in_at',
      cell: (d) => <span className="tabular-nums">{formatTime(d.first_in_at, local(d))}</span>,
    },
    {
      key: 'out',
      header: 'Out',
      cell: (d) => <span className="tabular-nums">{formatTime(d.last_out_at, local(d))}</span>,
    },
    {
      key: 'worked',
      header: 'Worked',
      sortKey: 'worked_minutes',
      hideBelow: 'sm',
      cell: (d) => <span className="tabular-nums">{formatMinutes(d.worked_minutes)}</span>,
    },
    {
      key: 'late',
      header: 'Late',
      sortKey: 'late_minutes',
      hideBelow: 'md',
      cell: (d) => (
        <span className={d.late_minutes > 0 ? 'text-status-late tabular-nums' : 'text-muted-foreground'}>
          {formatMinutes(d.late_minutes)}
        </span>
      ),
    },
    {
      key: 'early',
      header: 'Left early',
      hideBelow: 'lg',
      cell: (d) => (
        <span className={d.early_leave_minutes > 0 ? 'text-status-late tabular-nums' : 'text-muted-foreground'}>
          {formatMinutes(d.early_leave_minutes)}
        </span>
      ),
    },
  ]
}
