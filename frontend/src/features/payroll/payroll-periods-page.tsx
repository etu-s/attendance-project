import { useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2, Lock, LockOpen, Plus } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { DataTable, type Column } from '@/components/data-table'
import { Field } from '@/components/form-field'
import { PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { addDays, monthEnd, monthLabel, monthStart } from '@/features/scheduling/dates'
import { useCan, useDisplayPreferences, useSession } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { formatDateTime, formatLocalDate, todayIn } from '@/lib/format'
import { applyServerErrors, errorMessage } from '@/lib/forms'
import {
  usePayrollPeriods,
  useLockPayrollPeriod,
  useRelockPayrollPeriod,
  useUnlockPayrollPeriod,
  type PayrollPeriod,
} from './api'

// Mirrors LockPayrollPeriodRequest (+ PayrollPeriodService: finished days only).
function lockSchema(today: string) {
  return z
    .object({
      name: z.string().trim().min(1, 'Name the period.').max(80),
      start_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Pick a date.'),
      end_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Pick a date.'),
    })
    .refine((v) => v.end_date >= v.start_date, { path: ['end_date'], message: 'The end date is before the start date.' })
    .refine((v) => v.end_date < today, { path: ['end_date'], message: 'Only finished days can be locked: end before today.' })
}

type LockValues = z.infer<ReturnType<typeof lockSchema>>

function LockDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  // Default: last month, the usual payroll close.
  const lastMonth = monthStart(addDays(monthStart(today), -1))
  const lock = useLockPayrollPeriod()
  const form = useForm<LockValues>({
    resolver: zodResolver(lockSchema(today)),
    values: { name: monthLabel(lastMonth), start_date: lastMonth, end_date: monthEnd(lastMonth) },
  })
  const errors = form.formState.errors

  const submit = form.handleSubmit((values) =>
    lock.mutate(values, {
      onSuccess: () => {
        toast.success(`${values.name} is locked.`)
        onOpenChange(false)
      },
      onError: (e) => applyServerErrors(e, form.setError, ['name', 'start_date', 'end_date']),
    }),
  )

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Lock a payroll period</DialogTitle>
          <DialogDescription>
            Attendance, leave, overtime and schedules of these days are frozen until a Tenant Admin unlocks them.
          </DialogDescription>
        </DialogHeader>
        <form id="lock-period" className="grid gap-4" onSubmit={(e) => void submit(e)}>
          <Field label="Name" error={errors.name?.message}>
            <Input {...form.register('name')} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="First day" error={errors.start_date?.message}>
              <Input type="date" {...form.register('start_date')} />
            </Field>
            <Field label="Last day" error={errors.end_date?.message}>
              <Input type="date" max={addDays(today, -1)} {...form.register('end_date')} />
            </Field>
          </div>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form="lock-period" disabled={lock.isPending}>
            {lock.isPending ? <Loader2 className="animate-spin" /> : <Lock />} Lock period
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

const unlockSchema = z.object({ reason: z.string().trim().min(3, 'Say why it is unlocked.').max(255) })

function UnlockDialog({ period, onClose }: { period: PayrollPeriod | null; onClose: () => void }) {
  const unlock = useUnlockPayrollPeriod()
  const form = useForm<z.infer<typeof unlockSchema>>({ resolver: zodResolver(unlockSchema), values: { reason: '' } })

  const submit = form.handleSubmit(({ reason }) => {
    if (!period) return
    unlock.mutate(
      { id: period.id, reason },
      {
        onSuccess: () => {
          toast.success(`${period.name} is unlocked; its days are being recalculated.`)
          onClose()
        },
        onError: (e) => applyServerErrors(e, form.setError, ['reason']),
      },
    )
  })

  return (
    <Dialog open={period !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Unlock {period?.name}</DialogTitle>
          <DialogDescription>
            Its days become editable again and are recalculated, which can change figures already handed to payroll. The reason is
            kept in the audit log.
          </DialogDescription>
        </DialogHeader>
        <form id="unlock-period" onSubmit={(e) => void submit(e)}>
          <Field label="Reason" error={form.formState.errors.reason?.message}>
            <Textarea rows={3} {...form.register('reason')} />
          </Field>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="unlock-period" variant="destructive" disabled={unlock.isPending}>
            {unlock.isPending ? <Loader2 className="animate-spin" /> : <LockOpen />} Unlock
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

/**
 * Payroll periods: HR locks finished days once payroll is handed over; only a
 * Tenant Admin can unlock them again.
 */
export function PayrollPeriodsPage() {
  const can = useCan()
  const prefs = useDisplayPreferences()
  const list = useListParams({ defaultSort: '-start_date', filters: ['status'], defaultPerPage: 25 })
  const periods = usePayrollPeriods({ page: list.page, per_page: list.perPage, status: list.filter('status') || undefined })
  const relock = useRelockPayrollPeriod()
  const [locking, setLocking] = useState(false)
  const [unlocking, setUnlocking] = useState<PayrollPeriod | null>(null)

  const columns: Column<PayrollPeriod>[] = [
    {
      key: 'name',
      header: 'Period',
      cell: (p) => (
        <div>
          <p className="font-medium">{p.name}</p>
          <p className="text-muted-foreground text-xs">
            {formatLocalDate(p.start_date, prefs)} – {formatLocalDate(p.end_date, prefs)}
          </p>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (p) => (
        <StatusBadge tone={p.status === 'locked' ? 'info' : 'neutral'}>
          {p.status === 'locked' ? 'Locked' : 'Unlocked'}
        </StatusBadge>
      ),
    },
    {
      key: 'by',
      header: 'Last change',
      hideBelow: 'md',
      cell: (p) =>
        p.status === 'locked' ? (
          <span className="text-sm">
            Locked by {p.locked_by?.name ?? 'someone'} · {p.locked_at ? formatDateTime(p.locked_at, prefs) : ''}
          </span>
        ) : (
          <div className="text-sm">
            <p>
              Unlocked by {p.unlocked_by?.name ?? 'someone'} · {p.unlocked_at ? formatDateTime(p.unlocked_at, prefs) : ''}
            </p>
            {p.unlock_reason && <p className="text-muted-foreground text-xs">“{p.unlock_reason}”</p>}
          </div>
        ),
    },
    {
      key: 'actions',
      header: 'Actions',
      cell: (p) =>
        p.status === 'locked' ? (
          can('payroll-periods.unlock') ? (
            <Button variant="outline" size="sm" onClick={() => setUnlocking(p)}>
              <LockOpen /> Unlock
            </Button>
          ) : (
            <span className="text-muted-foreground text-xs">Tenant Admin can unlock</span>
          )
        ) : (
          can('payroll-periods.manage') && (
            <Button
              variant="outline"
              size="sm"
              disabled={relock.isPending}
              onClick={() =>
                relock.mutate(p.id, {
                  onSuccess: () => toast.success(`${p.name} is locked again.`),
                  onError: (e) => toast.error(errorMessage(e)),
                })
              }
            >
              <Lock /> Lock again
            </Button>
          )
        ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Payroll periods"
        description="Locked days are frozen: no corrections, leave, overtime decisions or schedule changes, and no recalculation."
        actions={
          can('payroll-periods.manage') && (
            <Button onClick={() => setLocking(true)}>
              <Plus /> Lock a period
            </Button>
          )
        }
      />
      <DataTable
        caption="Payroll periods"
        columns={columns}
        list={list}
        query={periods}
        rowKey={(p) => p.id}
        emptyTitle="No payroll periods yet"
        emptyDescription="Lock a period once its attendance and overtime have gone to payroll."
      />
      <LockDialog open={locking} onOpenChange={setLocking} />
      <UnlockDialog period={unlocking} onClose={() => setUnlocking(null)} />
    </>
  )
}
