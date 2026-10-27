import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { AlertTriangle, Loader2 } from 'lucide-react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Field } from '@/components/form-field'
import { SearchInput } from '@/components/search-input'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { Textarea } from '@/components/ui/textarea'
import { useEmployees } from '@/features/employees/api'
import { useSession } from '@/features/tenant-auth/hooks'
import { useDebouncedValue } from '@/hooks/use-debounced-value'
import { todayIn } from '@/lib/format'
import { applyServerErrors } from '@/lib/forms'
import { useLeavePreview, useLeaveTypes, useRequestLeave, type LeavePayload } from './api'
import { formatDays } from './format'

const date = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Pick a date.')

// Mirrors LeaveRequestPayload + LeaveRequestService (range, halves, balance and overlaps are checked by the API).
const schema = z
  .object({
    employee_id: z.string().min(1, 'Choose an employee.'),
    leave_type_id: z.string().min(1, 'Choose a leave type.'),
    start_date: date,
    end_date: date,
    half_start: z.boolean(),
    half_end: z.boolean(),
    reason: z.string().max(500),
  })
  .superRefine((v, ctx) => {
    if (v.end_date < v.start_date) ctx.addIssue({ code: 'custom', path: ['end_date'], message: 'Ends before it starts.' })
    if (v.start_date === v.end_date && v.half_start && v.half_end)
      ctx.addIssue({ code: 'custom', path: ['half_end'], message: 'Choose the morning or the afternoon, not both.' })
  })

type Values = z.infer<typeof schema>

function blankValues(employeeId: number | null, today: string): Values {
  return {
    employee_id: employeeId === null ? '' : String(employeeId),
    leave_type_id: '',
    start_date: today,
    end_date: today,
    half_start: false,
    half_end: false,
    reason: '',
  }
}

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

/** Counted days and the balance afterwards, as the API computes them. */
function Preview({ payload }: { payload: LeavePayload | null }) {
  const preview = useLeavePreview(useDebouncedValue(payload, 400))

  if (payload === null) {
    return null
  }
  if (preview.isPending || preview.isError) {
    return preview.isError ? null : <Skeleton className="h-14 w-full" />
  }

  const data = preview.data

  return (
    <div className="bg-muted/40 space-y-1 rounded-lg border px-3 py-2 text-sm" aria-live="polite">
      <p>
        <span className="font-medium">{formatDays(data.days)}</span> counted
        <span className="text-muted-foreground"> (scheduled working days only; holidays and days off are free)</span>
      </p>
      {data.years.map((y) =>
        y.available === null ? null : (
          <p key={y.leave_year} className="text-muted-foreground">
            {data.years.length > 1 && `${String(y.leave_year)}: `}
            {formatDays(y.available)} available → {formatDays(y.after ?? 0)} after
          </p>
        ),
      )}
      {data.problem && (
        <p className="text-status-absent flex items-center gap-2">
          <AlertTriangle className="size-4" aria-hidden /> {data.problem}
        </p>
      )}
    </div>
  )
}

/**
 * Request leave. With `employeeId` the request is for that employee (My leave,
 * an employee profile); without, an employee picker is shown (HR).
 */
export function LeaveRequestDialog({
  open,
  employeeId,
  onClose,
}: {
  open: boolean
  employeeId: number | null
  onClose: () => void
}) {
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  const types = useLeaveTypes()
  const request = useRequestLeave()
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: blankValues(employeeId, today) })
  const values = useWatch({ control: form.control })

  useEffect(() => {
    if (open) form.reset(blankValues(employeeId, today))
  }, [open, employeeId, today, form])

  const complete = schema.safeParse(values)
  const payload: LeavePayload | null = complete.success
    ? {
        employee_id: Number(complete.data.employee_id),
        leave_type_id: Number(complete.data.leave_type_id),
        start_date: complete.data.start_date,
        end_date: complete.data.end_date,
        half_start: complete.data.half_start,
        half_end: complete.data.half_end,
      }
    : null

  const submit = form.handleSubmit(async (v) => {
    try {
      const saved = await request.mutateAsync({
        employee_id: Number(v.employee_id),
        leave_type_id: Number(v.leave_type_id),
        start_date: v.start_date,
        end_date: v.end_date,
        half_start: v.half_start,
        half_end: v.half_end,
        reason: v.reason.trim() || null,
      })
      toast.success(
        saved.status === 'approved' ? 'Leave recorded and approved.' : 'Leave requested. You will see the decision here.',
      )
      onClose()
    } catch (error) {
      applyServerErrors(error, form.setError, ['employee_id', 'leave_type_id', 'start_date', 'end_date', 'half_end', 'reason'])
    }
  })

  const errors = form.formState.errors

  return (
    <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Request leave</DialogTitle>
          <DialogDescription>Only scheduled working days are taken from the balance.</DialogDescription>
        </DialogHeader>
        <form id="leave-form" onSubmit={submit} className="grid gap-4" noValidate>
          {employeeId === null && (
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
          <Controller
            control={form.control}
            name="leave_type_id"
            render={({ field }) => (
              <Field label="Leave type" error={errors.leave_type_id?.message}>
                {(controlProps) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger className="w-full" {...controlProps}>
                      <SelectValue placeholder={types.isPending ? 'Loading…' : 'Choose a type'} />
                    </SelectTrigger>
                    <SelectContent>
                      {(types.data ?? []).map((t) => (
                        <SelectItem key={t.id} value={String(t.id)}>
                          {t.name}
                          {!t.is_paid && ' (unpaid)'}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              </Field>
            )}
          />
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
              <Field label="From" error={errors.start_date?.message}>
                <Input type="date" {...form.register('start_date')} />
              </Field>
              <Controller
                control={form.control}
                name="half_start"
                render={({ field }) => (
                  <label className="flex items-center gap-2 text-sm">
                    <Checkbox checked={field.value} onCheckedChange={(c) => field.onChange(c === true)} />
                    Start in the afternoon
                  </label>
                )}
              />
            </div>
            <div className="grid gap-2">
              <Field label="Until" error={errors.end_date?.message ?? errors.half_end?.message}>
                <Input type="date" {...form.register('end_date')} />
              </Field>
              <Controller
                control={form.control}
                name="half_end"
                render={({ field }) => (
                  <label className="flex items-center gap-2 text-sm">
                    <Checkbox checked={field.value} onCheckedChange={(c) => field.onChange(c === true)} />
                    End at midday
                  </label>
                )}
              />
            </div>
          </div>
          <Preview payload={payload} />
          <Field label="Reason (optional)" error={errors.reason?.message}>
            <Textarea rows={2} maxLength={500} {...form.register('reason')} />
          </Field>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="leave-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            Submit
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
