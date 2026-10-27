import { Check, Undo2, X } from 'lucide-react'
import type { Column } from '@/components/data-table'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { formatDateTime } from '@/lib/format'
import type { LeaveRequest } from './api'
import { formatDays, leaveRange, leaveStatusTone } from './format'
import type { LeaveAction } from './leave-action-dialog'

/**
 * Columns for leave request lists. Actions appear only when the API says the
 * viewer may take them (can_decide / can_cancel).
 */
export function useLeaveColumns(
  onAction: (action: LeaveAction) => void,
  options: { showEmployee: boolean },
): Column<LeaveRequest>[] {
  const prefs = useDisplayPreferences()

  return [
    ...(options.showEmployee
      ? [
          {
            key: 'employee',
            header: 'Employee',
            cell: (r: LeaveRequest) => (
              <div>
                <p className="font-medium">{r.employee.full_name}</p>
                <p className="text-muted-foreground text-xs">{r.employee.employee_code}</p>
              </div>
            ),
          },
        ]
      : []),
    {
      key: 'type',
      header: 'Type',
      cell: (r) => (
        <span className="flex items-center gap-2">
          <span
            className="size-2.5 rounded-full"
            style={{ backgroundColor: r.type.color ?? 'var(--status-leave)' }}
            aria-hidden
          />
          {r.type.name}
        </span>
      ),
    },
    {
      key: 'dates',
      header: 'Dates',
      sortKey: 'start_date',
      cell: (r) => (
        <div>
          <p className="tabular-nums">{leaveRange(r, prefs)}</p>
          <p className="text-muted-foreground text-xs">{formatDays(r.days)}</p>
        </div>
      ),
    },
    {
      key: 'reason',
      header: 'Reason',
      hideBelow: 'lg',
      cell: (r) => <span className="text-muted-foreground">{r.reason ?? '—'}</span>,
    },
    {
      key: 'requested',
      header: 'Requested',
      sortKey: 'created_at',
      hideBelow: 'md',
      cell: (r) => (
        <div className="text-xs">
          <p>{r.requested_by?.name ?? '—'}</p>
          <p className="text-muted-foreground">{formatDateTime(r.created_at, prefs)}</p>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <div className="space-y-1">
          <StatusBadge tone={leaveStatusTone[r.status]}>{r.status}</StatusBadge>
          {r.decision_note && <p className="text-muted-foreground text-xs">{r.decision_note}</p>}
        </div>
      ),
    },
    {
      key: 'actions',
      header: '',
      className: 'w-32 text-right',
      cell: (r) => (
        <div className="flex justify-end gap-1">
          {r.can_decide && (
            <>
              <Button
                variant="ghost"
                size="icon"
                className="size-8"
                aria-label={`Approve leave of ${r.employee.full_name}`}
                onClick={() => onAction({ request: r, action: 'approve' })}
              >
                <Check />
              </Button>
              <Button
                variant="ghost"
                size="icon"
                className="size-8"
                aria-label={`Reject leave of ${r.employee.full_name}`}
                onClick={() => onAction({ request: r, action: 'reject' })}
              >
                <X />
              </Button>
            </>
          )}
          {r.can_cancel && (
            <Button
              variant="ghost"
              size="icon"
              className="size-8"
              aria-label={`Cancel leave of ${r.employee.full_name}`}
              onClick={() => onAction({ request: r, action: 'cancel' })}
            >
              <Undo2 />
            </Button>
          )}
        </div>
      ),
    },
  ]
}
