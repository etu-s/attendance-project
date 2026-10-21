import { useState } from 'react'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { ErrorState } from '@/components/states'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useCan, useDisplayPreferences, useSession } from '@/features/tenant-auth/hooks'
import { formatLocalDate, todayIn } from '@/lib/format'
import { errorMessage } from '@/lib/forms'
import { useDeleteTemporary, useEmployeeSchedule, useTemporarySchedules, type TemporarySchedule } from './api'
import { monthEnd, monthStart } from './dates'
import { MonthNav, ScheduleCalendar } from './schedule-calendar'
import { TemporaryDialog } from './temporary-dialog'

/** "Schedule" tab of an employee profile. */
export function EmployeeScheduleTab({ employeeId }: { employeeId: number }) {
  const can = useCan()
  const prefs = useDisplayPreferences()
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  const [month, setMonth] = useState(monthStart(today))
  const schedule = useEmployeeSchedule(employeeId, month, monthEnd(month))
  const temporaries = useTemporarySchedules(employeeId)
  const remove = useDeleteTemporary()
  const manage = can('schedules.manage')
  const [editing, setEditing] = useState<TemporarySchedule | 'new' | null>(null)
  const [deleting, setDeleting] = useState<TemporarySchedule | null>(null)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <MonthNav month={month} onChange={setMonth} today={today} />
        {manage && (
          <Button variant="outline" size="sm" onClick={() => setEditing('new')}>
            <Plus /> Temporary change
          </Button>
        )}
      </div>

      {schedule.isError ? (
        <ErrorState error={schedule.error} onRetry={() => schedule.refetch()} />
      ) : (
        <ScheduleCalendar
          days={schedule.data}
          loading={schedule.isPending}
          weekStart={workspace.week_start}
          today={today}
          prefs={prefs}
        />
      )}

      <div className="space-y-2">
        <p className="text-sm font-medium">Temporary changes</p>
        {temporaries.isPending ? (
          <Skeleton className="h-12 w-full" />
        ) : (temporaries.data ?? []).length === 0 ? (
          <p className="text-muted-foreground text-sm">None. The standing schedule applies.</p>
        ) : (
          <ul className="divide-y rounded-lg border">
            {(temporaries.data ?? []).map((t) => (
              <li key={t.id} className="flex flex-wrap items-center gap-3 px-3 py-2 text-sm">
                <span className="tabular-nums">
                  {formatLocalDate(t.date_from, prefs)}
                  {t.date_to !== t.date_from && ` – ${formatLocalDate(t.date_to, prefs)}`}
                </span>
                <span className="font-medium">{t.day_off ? 'Day off' : t.shift?.name}</span>
                {t.reason && <span className="text-muted-foreground">{t.reason}</span>}
                {manage && (
                  <span className="ml-auto flex gap-1">
                    <Button
                      variant="ghost"
                      size="icon"
                      className="size-8"
                      aria-label="Edit temporary change"
                      onClick={() => setEditing(t)}
                    >
                      <Pencil />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="size-8"
                      aria-label="Delete temporary change"
                      onClick={() => setDeleting(t)}
                    >
                      <Trash2 />
                    </Button>
                  </span>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>

      <TemporaryDialog employeeId={employeeId} target={editing} onClose={() => setEditing(null)} />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title="Remove this temporary change?"
        description={<p>The standing schedule applies again for those dates.</p>}
        confirmLabel="Remove"
        destructive
        pending={remove.isPending}
        onConfirm={() => {
          if (!deleting) return
          remove.mutate(deleting.id, {
            onSuccess: () => {
              toast.success('Temporary change removed.')
              setDeleting(null)
            },
            onError: (e) => toast.error(errorMessage(e)),
          })
        }}
      />
    </div>
  )
}
