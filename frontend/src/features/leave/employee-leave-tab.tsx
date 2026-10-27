import { useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { ChevronLeft, ChevronRight, Loader2, Plus, Scale } from 'lucide-react'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Field } from '@/components/form-field'
import { ErrorState } from '@/components/states'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useCan, useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { formatDateTime } from '@/lib/format'
import { applyServerErrors } from '@/lib/forms'
import { cn } from '@/lib/utils'
import { useAdjustLeave, useEmployeeLeaveBalances, useLeaveLedger, useLeaveTypes } from './api'
import { BalanceCards } from './balance-cards'
import { formatDays, ledgerKindLabel } from './format'
import { LeaveRequestDialog } from './leave-request-dialog'

// Mirrors LeaveAdjustmentRequest.
const adjustSchema = z.object({
  leave_type_id: z.string().min(1, 'Choose a leave type.'),
  days: z
    .string()
    .regex(/^-?\d{1,3}(\.[05])?$/, 'Whole or half days; negative to take away.')
    .refine((v) => Number(v) !== 0, 'Enter a non-zero amount.'),
  reason: z.string().trim().min(3, 'Say why.').max(255),
})

type AdjustValues = z.infer<typeof adjustSchema>

function AdjustDialog({
  employeeId,
  year,
  open,
  onClose,
}: {
  employeeId: number
  year: number
  open: boolean
  onClose: () => void
}) {
  const types = useLeaveTypes()
  const adjust = useAdjustLeave()
  const form = useForm<AdjustValues>({
    resolver: zodResolver(adjustSchema),
    defaultValues: { leave_type_id: '', days: '', reason: '' },
  })

  const submit = form.handleSubmit(async (v) => {
    try {
      await adjust.mutateAsync({
        employee_id: employeeId,
        leave_type_id: Number(v.leave_type_id),
        days: Number(v.days),
        leave_year: year,
        reason: v.reason,
      })
      toast.success('Balance adjusted.')
      form.reset()
      onClose()
    } catch (error) {
      applyServerErrors(error, form.setError, ['leave_type_id', 'days', 'reason'])
    }
  })

  const errors = form.formState.errors

  return (
    <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Adjust balance</DialogTitle>
          <DialogDescription>Adds an entry to the {year} leave year. Balances are never edited directly.</DialogDescription>
        </DialogHeader>
        <form id="adjust-form" onSubmit={submit} className="grid gap-4" noValidate>
          <div className="grid gap-4 sm:grid-cols-2">
            <Controller
              control={form.control}
              name="leave_type_id"
              render={({ field }) => (
                <Field label="Leave type" error={errors.leave_type_id?.message}>
                  {(controlProps) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger className="w-full" {...controlProps}>
                        <SelectValue placeholder="Choose a type" />
                      </SelectTrigger>
                      <SelectContent>
                        {(types.data ?? [])
                          .filter((t) => !t.unlimited)
                          .map((t) => (
                            <SelectItem key={t.id} value={String(t.id)}>
                              {t.name}
                            </SelectItem>
                          ))}
                      </SelectContent>
                    </Select>
                  )}
                </Field>
              )}
            />
            <Field label="Days" error={errors.days?.message} hint="e.g. 2 or -0.5">
              <Input inputMode="decimal" {...form.register('days')} />
            </Field>
          </div>
          <Field label="Reason" error={errors.reason?.message}>
            <Input placeholder="e.g. Compensation for weekend work" {...form.register('reason')} />
          </Field>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="adjust-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            Save
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

/** "Leave" tab of an employee profile: balances per leave year and the ledger behind them. */
export function EmployeeLeaveTab({ employeeId }: { employeeId: number }) {
  const can = useCan()
  const prefs = useDisplayPreferences()
  const [year, setYear] = useState<number | undefined>(undefined)
  const balances = useEmployeeLeaveBalances(employeeId, year)
  const ledger = useLeaveLedger(employeeId, year)
  const current = balances.data?.leave_year
  const [requesting, setRequesting] = useState(false)
  const [adjusting, setAdjusting] = useState(false)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-1">
          <Button
            variant="outline"
            size="icon"
            className="size-8"
            aria-label="Previous leave year"
            disabled={current === undefined}
            onClick={() => current !== undefined && setYear(current - 1)}
          >
            <ChevronLeft />
          </Button>
          <p className="min-w-28 text-center text-sm font-medium" aria-live="polite">
            {current === undefined ? '…' : `Leave year ${String(current)}`}
          </p>
          <Button
            variant="outline"
            size="icon"
            className="size-8"
            aria-label="Next leave year"
            disabled={current === undefined}
            onClick={() => current !== undefined && setYear(current + 1)}
          >
            <ChevronRight />
          </Button>
        </div>
        <div className="flex gap-2">
          {can('leave.manage') && current !== undefined && (
            <Button variant="outline" size="sm" onClick={() => setAdjusting(true)}>
              <Scale /> Adjust
            </Button>
          )}
          {can('leave.request') && (
            <Button size="sm" onClick={() => setRequesting(true)}>
              <Plus /> Request leave
            </Button>
          )}
        </div>
      </div>

      <BalanceCards
        balances={balances.data?.data}
        loading={balances.isPending}
        error={balances.isError ? balances.error : null}
        onRetry={() => balances.refetch()}
      />

      <section className="space-y-2" aria-labelledby="ledger-title">
        <h3 id="ledger-title" className="text-sm font-medium">
          Ledger
        </h3>
        {ledger.isError ? (
          <ErrorState error={ledger.error} onRetry={() => ledger.refetch()} />
        ) : ledger.isPending ? (
          <Skeleton className="h-24 w-full" />
        ) : ledger.data.data.length === 0 ? (
          <p className="text-muted-foreground text-sm">No entries in this leave year.</p>
        ) : (
          <div className="overflow-x-auto rounded-lg border">
            <Table>
              <caption className="sr-only">Leave ledger</caption>
              <TableHeader>
                <TableRow className="hover:bg-transparent">
                  <TableHead>When</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Entry</TableHead>
                  <TableHead className="text-right">Days</TableHead>
                  <TableHead className="hidden md:table-cell">Note</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {ledger.data.data.map((e) => (
                  <TableRow key={e.id}>
                    <TableCell className="text-xs tabular-nums">{formatDateTime(e.created_at, prefs)}</TableCell>
                    <TableCell>{e.type.name}</TableCell>
                    <TableCell>{ledgerKindLabel[e.kind]}</TableCell>
                    <TableCell
                      className={cn('text-right tabular-nums', e.days < 0 ? 'text-status-absent' : 'text-status-present')}
                    >
                      {e.days > 0 ? '+' : ''}
                      {formatDays(e.days)}
                    </TableCell>
                    <TableCell className="text-muted-foreground hidden text-sm md:table-cell">
                      {e.reason ?? '—'}
                      {e.created_by && ` · ${e.created_by.name}`}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </section>

      <LeaveRequestDialog open={requesting} employeeId={employeeId} onClose={() => setRequesting(false)} />
      {current !== undefined && (
        <AdjustDialog employeeId={employeeId} year={current} open={adjusting} onClose={() => setAdjusting(false)} />
      )}
    </div>
  )
}
