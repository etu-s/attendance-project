import { StatusBadge } from '@/components/status-badge'
import { useCan } from '@/features/tenant-auth/hooks'
import { useEmployeeOvertime, useMyOvertime } from './api'
import { formatDuration, overtimeBreakdown, overtimeStatusTone } from './format'

/**
 * One day's overtime in the attendance drawer. Approvers read it through the
 * employee endpoint; everyone else can only ever open their own days, so the
 * "me" endpoint is enough. Errors stay silent: the drawer is about attendance.
 */
export function DayOvertime({ employeeId, workDate }: { employeeId: number; workDate: string }) {
  const can = useCan()
  const viewer = can('overtime.view')
  const other = useEmployeeOvertime(employeeId, workDate, workDate, viewer)
  const mine = useMyOvertime(workDate, workDate, !viewer)
  const records = (viewer ? other.data : mine.data) ?? []

  if (records.length === 0) return null

  return (
    <section className="space-y-2" aria-labelledby="day-overtime-title">
      <h3 id="day-overtime-title" className="text-sm font-medium">
        Overtime
      </h3>
      <ul className="space-y-1">
        {records.map((r) => (
          <li key={r.id} className="flex flex-wrap items-center gap-2 text-sm">
            <span className="font-medium tabular-nums">
              {r.status === 'approved' && r.approved_minutes !== null
                ? formatDuration(r.approved_minutes)
                : formatDuration(r.calculated_minutes)}
            </span>
            <span className="text-muted-foreground text-xs">
              {r.source === 'manual' ? (r.reason ?? 'Manual') : overtimeBreakdown(r)}
            </span>
            <StatusBadge tone={overtimeStatusTone[r.status]}>{r.status}</StatusBadge>
          </li>
        ))}
      </ul>
    </section>
  )
}
