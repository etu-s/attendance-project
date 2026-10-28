import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2 } from 'lucide-react'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Field } from '@/components/form-field'
import { SearchInput } from '@/components/search-input'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { useEmployees } from '@/features/employees/api'
import { useDisplayPreferences, useSession } from '@/features/tenant-auth/hooks'
import { formatLocalDate, todayIn } from '@/lib/format'
import { applyServerErrors, errorMessage } from '@/lib/forms'
import { useDecideOvertime, useSaveOvertimeEntry, type OvertimeRecord } from './api'
import { formatDuration, overtimeBreakdown, overtimeKindLabel } from './format'

export type OvertimeDecision = { record: OvertimeRecord; decision: 'approve' | 'reject' }

/** Approve (optionally fewer minutes) or reject one record. */
export function DecisionDialog({ target, onClose }: { target: OvertimeDecision | null; onClose: () => void }) {
  if (!target) {
    return null
  }

  // Keyed so each opened record starts from its own calculated minutes.
  return <DecisionForm key={`${String(target.record.id)}-${target.decision}`} target={target} onClose={onClose} />
}

function DecisionForm({ target, onClose }: { target: OvertimeDecision; onClose: () => void }) {
  const prefs = useDisplayPreferences()
  const decide = useDecideOvertime()
  const [minutes, setMinutes] = useState(String(target.record.calculated_minutes))
  const [note, setNote] = useState('')

  const { record, decision } = target
  const approving = decision === 'approve'
  const value = Number(minutes)
  const invalid = approving && (!Number.isInteger(value) || value < 1 || value > record.calculated_minutes)

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{approving ? 'Approve overtime' : 'Reject overtime'}</DialogTitle>
          <DialogDescription>
            {record.employee.full_name} · {formatLocalDate(record.work_date, prefs)} · {formatDuration(record.calculated_minutes)}
          </DialogDescription>
        </DialogHeader>
        <p className="text-muted-foreground text-sm">{overtimeBreakdown(record)}</p>
        {record.reason && <p className="text-sm">“{record.reason}”</p>}
        {approving && (
          <div className="grid gap-1.5">
            <Label htmlFor="ot-minutes">Minutes to approve</Label>
            <Input
              id="ot-minutes"
              type="number"
              min={1}
              max={record.calculated_minutes}
              value={minutes}
              aria-invalid={invalid}
              onChange={(e) => setMinutes(e.target.value)}
            />
            <p className={invalid ? 'text-destructive text-xs' : 'text-muted-foreground text-xs'}>
              Between 1 and {record.calculated_minutes}; the weighted time shrinks in proportion.
            </p>
          </div>
        )}
        <div className="grid gap-1.5">
          <Label htmlFor="ot-note">Note (optional)</Label>
          <Textarea id="ot-note" rows={2} maxLength={255} value={note} onChange={(e) => setNote(e.target.value)} />
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Back
          </Button>
          <Button
            variant={approving ? 'default' : 'destructive'}
            disabled={decide.isPending || invalid}
            onClick={() =>
              decide.mutate(
                { id: record.id, decision, minutes: approving ? value : null, note: note.trim() || null },
                {
                  onSuccess: () => {
                    toast.success(approving ? 'Overtime approved.' : 'Overtime rejected.')
                    onClose()
                  },
                  onError: (e) => toast.error(errorMessage(e)),
                },
              )
            }
          >
            {decide.isPending && <Loader2 className="animate-spin" />}
            {approving ? 'Approve' : 'Reject'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

// Mirrors OvertimeEntryRequest + OvertimeService (no future dates, one manual entry per day).
const schema = z.object({
  employee_id: z.string().min(1, 'Choose an employee.'),
  work_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Pick a date.'),
  hours: z.string().regex(/^\d{1,2}$/, 'Whole hours.'),
  mins: z.string().regex(/^\d{1,2}$/, 'Minutes.'),
  kind: z.enum(['workday', 'rest_day', 'holiday']),
  reason: z.string().trim().min(3, 'Say what the work was.').max(255),
})

type Values = z.infer<typeof schema>

function EmployeePicker({
  value,
  onChange,
  controlProps,
}: {
  value: string
  onChange: (v: string) => void
  controlProps: object
}) {
  const [search, setSearch] = useState('')
  const employees = useEmployees({ search, per_page: 50, sort: 'name', status: 'active' })

  return (
    <div className="grid gap-2">
      <SearchInput value={search} onChange={setSearch} label="Search employees" />
      <Select value={value} onValueChange={onChange}>
        <SelectTrigger className="w-full" {...controlProps}>
          <SelectValue placeholder={employees.isPending ? 'Loading…' : 'Choose an employee'} />
        </SelectTrigger>
        <SelectContent>
          {(employees.data?.data ?? []).map((e) => (
            <SelectItem key={e.id} value={String(e.id)}>
              {e.full_name} · {e.employee_code}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  )
}

function blank(employeeId: number | null, today: string): Values {
  return {
    employee_id: employeeId === null ? '' : String(employeeId),
    work_date: today,
    hours: '1',
    mins: '0',
    kind: 'workday',
    reason: '',
  }
}

/**
 * Record (or edit, while pending) manual overtime for work no punch captured.
 * Entered by HR for someone else, it is approved at once.
 */
export function ManualEntryDialog({
  open,
  employeeId,
  editing,
  onClose,
}: {
  open: boolean
  employeeId: number | null
  editing: OvertimeRecord | null
  onClose: () => void
}) {
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  const save = useSaveOvertimeEntry()
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: blank(employeeId, today) })

  useEffect(() => {
    if (!open) return
    if (editing) {
      form.reset({
        employee_id: String(editing.employee.id),
        work_date: editing.work_date,
        hours: String(Math.floor(editing.calculated_minutes / 60)),
        mins: String(editing.calculated_minutes % 60),
        kind: editing.kind,
        reason: editing.reason ?? '',
      })
    } else {
      form.reset(blank(employeeId, today))
    }
  }, [open, editing, employeeId, today, form])

  const submit = form.handleSubmit(async (v) => {
    const minutes = Number(v.hours) * 60 + Number(v.mins)
    if (minutes < 1 || minutes > 1440) {
      form.setError('hours', { message: 'Between 1 minute and 24 hours.' })
      return
    }
    try {
      const saved = await save.mutateAsync({
        id: editing?.id,
        payload: editing
          ? { minutes, kind: v.kind, reason: v.reason }
          : { employee_id: Number(v.employee_id), work_date: v.work_date, minutes, kind: v.kind, reason: v.reason },
      })
      toast.success(saved.status === 'approved' ? 'Overtime recorded and approved.' : 'Overtime recorded.')
      onClose()
    } catch (error) {
      applyServerErrors(error, form.setError, ['employee_id', 'work_date', 'kind', 'reason'], { minutes: 'hours' })
    }
  })

  const errors = form.formState.errors

  return (
    <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{editing ? 'Edit manual overtime' : 'Record overtime'}</DialogTitle>
          <DialogDescription>For work no punch captured, such as a call-out or work off site.</DialogDescription>
        </DialogHeader>
        <form id="ot-entry-form" onSubmit={submit} className="grid gap-4" noValidate>
          {employeeId === null && !editing && (
            <Controller
              control={form.control}
              name="employee_id"
              render={({ field }) => (
                <Field label="Employee" error={errors.employee_id?.message}>
                  {(controlProps) => <EmployeePicker value={field.value} onChange={field.onChange} controlProps={controlProps} />}
                </Field>
              )}
            />
          )}
          <div className="grid gap-4 sm:grid-cols-[1fr_5rem_5rem]">
            <Field label="Date" error={errors.work_date?.message}>
              <Input type="date" max={today} disabled={editing !== null} {...form.register('work_date')} />
            </Field>
            <Field label="Hours" error={errors.hours?.message}>
              <Input inputMode="numeric" {...form.register('hours')} />
            </Field>
            <Field label="Minutes" error={errors.mins?.message}>
              <Input inputMode="numeric" {...form.register('mins')} />
            </Field>
          </div>
          <Controller
            control={form.control}
            name="kind"
            render={({ field }) => (
              <Field label="Rate">
                {(controlProps) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger className="w-full" {...controlProps}>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {(['workday', 'rest_day', 'holiday'] as const).map((k) => (
                        <SelectItem key={k} value={k}>
                          {overtimeKindLabel[k]}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              </Field>
            )}
          />
          <Field label="Reason" error={errors.reason?.message}>
            <Input placeholder="e.g. Server migration on Sunday" {...form.register('reason')} />
          </Field>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="ot-entry-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            Save
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
