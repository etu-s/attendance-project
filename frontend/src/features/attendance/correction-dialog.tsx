import { useEffect } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2 } from 'lucide-react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Field } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { formatLocalDate, formatTime } from '@/lib/format'
import { applyServerErrors } from '@/lib/forms'
import { useRequestCorrection, type AttendancePunch } from './api'
import { punchRoleLabel } from './status'
import { zonedToUtcIso, zoneLabel } from './time'

const date = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Pick a date.')

// Mirrors StoreCorrectionRequest + PunchCorrectionService (time near the work date, own punches only).
const schema = z
  .object({
    kind: z.enum(['add', 'ignore']),
    date,
    time: z.string(),
    raw_punch_id: z.string(),
    reason: z.string().trim().min(3, 'Say briefly what happened.').max(255),
  })
  .superRefine((v, ctx) => {
    if (v.kind === 'add' && !/^\d{2}:\d{2}$/.test(v.time))
      ctx.addIssue({ code: 'custom', path: ['time'], message: 'Enter the time of the missed punch.' })
    if (v.kind === 'ignore' && v.raw_punch_id === '')
      ctx.addIssue({ code: 'custom', path: ['raw_punch_id'], message: 'Choose the punch to ignore.' })
  })

type Values = z.infer<typeof schema>

export interface CorrectionTarget {
  employeeId: number
  employeeName: string
  workDate: string
  timezone: string
  punches: AttendancePunch[]
}

/**
 * Request (or, for HR, enter) a correction for one work date: add a missed
 * punch, or ignore a wrong one. Times are entered in the day's timezone.
 */
export function CorrectionDialog({ target, onClose }: { target: CorrectionTarget | null; onClose: () => void }) {
  const prefs = useDisplayPreferences()
  const request = useRequestCorrection()
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { kind: 'add', date: '', time: '', raw_punch_id: '', reason: '' },
  })
  const kind = useWatch({ control: form.control, name: 'kind' })

  useEffect(() => {
    if (target) form.reset({ kind: 'add', date: target.workDate, time: '', raw_punch_id: '', reason: '' })
  }, [target, form])

  if (!target) {
    return null
  }

  const local = { ...prefs, timezone: target.timezone }
  const ignorable = target.punches.filter((p) => p.raw_punch_id !== null && p.role !== 'ignored')

  const submit = form.handleSubmit(async (v) => {
    try {
      const saved = await request.mutateAsync({
        employee_id: target.employeeId,
        work_date: target.workDate,
        kind: v.kind,
        reason: v.reason.trim(),
        ...(v.kind === 'add'
          ? { punched_at: zonedToUtcIso(v.date, v.time, target.timezone) }
          : { raw_punch_id: Number(v.raw_punch_id) }),
      })
      toast.success(
        saved.status === 'approved' ? 'Correction applied. Attendance is being recalculated.' : 'Correction sent for approval.',
      )
      onClose()
    } catch (error) {
      applyServerErrors(error, form.setError, ['reason', 'raw_punch_id', 'kind'], { punched_at: 'time', work_date: 'date' })
    }
  })

  const errors = form.formState.errors

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Correct attendance</DialogTitle>
          <DialogDescription>
            {target.employeeName} · {formatLocalDate(target.workDate, prefs)}. Original punches are never changed.
          </DialogDescription>
        </DialogHeader>
        <form id="correction-form" onSubmit={submit} className="grid gap-4" noValidate>
          <Controller
            control={form.control}
            name="kind"
            render={({ field }) => (
              <Field label="What needs fixing">
                {(controlProps) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger className="w-full" {...controlProps}>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="add">A punch is missing</SelectItem>
                      <SelectItem value="ignore" disabled={ignorable.length === 0}>
                        A punch is wrong
                      </SelectItem>
                    </SelectContent>
                  </Select>
                )}
              </Field>
            )}
          />
          {kind === 'add' ? (
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Date" error={errors.date?.message} hint="Overnight shifts end the next day.">
                <Input type="date" {...form.register('date')} />
              </Field>
              <Field label={`Time (${zoneLabel(target.timezone)})`} error={errors.time?.message}>
                <Input type="time" {...form.register('time')} />
              </Field>
            </div>
          ) : (
            <Controller
              control={form.control}
              name="raw_punch_id"
              render={({ field }) => (
                <Field label="Punch to ignore" error={errors.raw_punch_id?.message}>
                  {(controlProps) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger className="w-full" {...controlProps}>
                        <SelectValue placeholder="Choose a punch" />
                      </SelectTrigger>
                      <SelectContent>
                        {ignorable.map((p) => (
                          <SelectItem key={p.id} value={String(p.raw_punch_id)}>
                            {formatTime(p.punched_at, local)} · {punchRoleLabel[p.role]}
                            {p.source ? ` · ${p.source}` : ''}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                </Field>
              )}
            />
          )}
          <Field label="Reason" error={errors.reason?.message}>
            <Textarea rows={3} placeholder="e.g. Forgot to punch out, left at 17:00" {...form.register('reason')} />
          </Field>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="correction-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            Submit
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
