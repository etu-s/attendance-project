import { EmptyState, ErrorState } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Skeleton } from '@/components/ui/skeleton'
import { useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { formatLocalDate } from '@/lib/format'
import type { OvertimeRecord } from './api'
import { formatDuration, overtimeBreakdown, overtimeStatusTone } from './format'

/** A month of one person's overtime as a simple list (works on phones). */
export function OvertimeList({
  records,
  loading,
  error,
  onRetry,
}: {
  records: OvertimeRecord[] | undefined
  loading: boolean
  error: unknown
  onRetry: () => void
}) {
  const prefs = useDisplayPreferences()

  if (error) {
    return <ErrorState error={error} onRetry={onRetry} />
  }
  if (loading || !records) {
    return <Skeleton className="h-32 w-full" />
  }
  if (records.length === 0) {
    return <EmptyState title="No overtime this month" description="Overtime appears once attendance is calculated." />
  }

  const approved = records.reduce((sum, r) => sum + (r.status === 'approved' ? (r.approved_minutes ?? 0) : 0), 0)

  return (
    <div className="space-y-2">
      <p className="text-sm">
        Approved this month: <span className="font-medium">{formatDuration(approved)}</span>
      </p>
      <ul className="divide-y rounded-lg border">
        {records.map((r) => (
          <li key={r.id} className="flex flex-wrap items-center gap-3 px-3 py-2 text-sm">
            <span className="w-28 tabular-nums">{formatLocalDate(r.work_date, prefs)}</span>
            <span className="font-medium tabular-nums">
              {r.status === 'approved' && r.approved_minutes !== null
                ? formatDuration(r.approved_minutes)
                : formatDuration(r.calculated_minutes)}
            </span>
            <span className="text-muted-foreground min-w-0 flex-1 text-xs">
              {r.source === 'manual' ? (r.reason ?? 'Manual') : overtimeBreakdown(r)}
            </span>
            <StatusBadge tone={overtimeStatusTone[r.status]}>{r.status}</StatusBadge>
          </li>
        ))}
      </ul>
    </div>
  )
}
