import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2 } from 'lucide-react'
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
import { useEmployees } from '@/features/employees/api'
import { useSession } from '@/features/tenant-auth/hooks'
import { useShifts } from '@/features/timetables/api'
import { todayIn } from '@/lib/format'
import { applyServerErrors } from '@/lib/forms'
import { useSaveTemporary, type TemporarySchedule } from './api'

const date = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Pick a date.')

// Mirrors TemporaryScheduleRequest + TemporaryScheduleService (overlaps checked by the API).
const schema = z
  .object({
    employee_id: z.string().min(1, 'Choose an employee.'),
    date_from: date,
    date_to: date,
    day_off: z.boolean(),
    shift_id: z.string(),
    anchor_date: z.union([z.literal(''), date]),
    reason: z.string().max(255),
  })
  .superRefine((v, ctx) => {
    if (v.date_to < v.date_from) ctx.addIssue({ code: 'custom', path: ['date_to'], message: 'Ends before it starts.' })
    if (!v.day_off && v.shift_id === '')
      ctx.addIssue({ code: 'custom', path: ['shift_id'], message: 'Choose a shift, or make it a day off.' })
  })

type Values = z.infer<typeof schema>

function blankValues(employeeId: number | null, today: string): Values {
  return {
    employee_id: employeeId === null ? '' : String(employeeId),
    date_from: today,
    date_to: today,
    day_off: false,
    shift_id: '',
    anchor_date: '',
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

/**
 * Create or edit one employee's temporary change. With `employeeId` null a
 * new change starts with an employee picker (the Schedules page); editing
 * keeps the change's own employee.
 */
export function TemporaryDialog({
  employeeId,
  target,
  onClose,
}: {
  employeeId: number | null
  target: TemporarySchedule | 'new' | null
  onClose: () => void
}) {
  const { workspace } = useSession()
  const save = useSaveTemporary()
  const shifts = useShifts({ is_active: 1, per_page: 100, sort: 'name' })
  const today = todayIn(workspace.timezone)
  const fixedEmployee = target && target !== 'new' ? target.employee_id : employeeId
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: blankValues(employeeId, today) })
  const dayOff = useWatch({ control: form.control, name: 'day_off' })

  useEffect(() => {
    if (target === 'new') form.reset(blankValues(employeeId, today))
    else if (target) {
      form.reset({
        employee_id: String(target.employee_id),
        date_from: target.date_from,
        date_to: target.date_to,
        day_off: target.day_off,
        shift_id: target.shift ? String(target.shift.id) : '',
        anchor_date: target.anchor_date ?? '',
        reason: target.reason ?? '',
      })
    }
  }, [target, employeeId, today, form])

  const submit = form.handleSubmit(async (v) => {
    try {
      await save.mutateAsync({
        id: target && target !== 'new' ? target.id : undefined,
        employeeId: Number(v.employee_id),
        payload: {
          date_from: v.date_from,
          date_to: v.date_to,
          shift_id: v.day_off ? null : Number(v.shift_id),
          anchor_date: v.day_off || v.anchor_date === '' ? null : v.anchor_date,
          reason: v.reason || null,
        },
      })
      toast.success('Temporary schedule saved.')
      onClose()
    } catch (error) {
      applyServerErrors(error, form.setError, ['date_from', 'date_to', 'shift_id', 'anchor_date', 'reason'])
    }
  })

  const errors = form.formState.errors
  const editingName = target && target !== 'new' ? target.employee?.full_name : undefined

  return (
    <Dialog open={target !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{target === 'new' ? 'Temporary change' : 'Edit temporary change'}</DialogTitle>
          <DialogDescription>
            {editingName ? `${editingName}: ` : ''}Overrides every other schedule and holidays for these dates.
          </DialogDescription>
        </DialogHeader>
        <form id="temporary-form" onSubmit={submit} className="grid gap-4" noValidate>
          {fixedEmployee === null && (
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
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="From" error={errors.date_from?.message}>
              <Input type="date" {...form.register('date_from')} />
            </Field>
            <Field label="Until" error={errors.date_to?.message}>
              <Input type="date" {...form.register('date_to')} />
            </Field>
          </div>
          <Controller
            control={form.control}
            name="day_off"
            render={({ field }) => (
              <label className="flex items-center gap-2 text-sm">
                <Checkbox checked={field.value} onCheckedChange={(c) => field.onChange(c === true)} />
                Days off (no work)
              </label>
            )}
          />
          {!dayOff && (
            <div className="grid gap-4 sm:grid-cols-2">
              <Controller
                control={form.control}
                name="shift_id"
                render={({ field }) => (
                  <Field label="Shift" error={errors.shift_id?.message}>
                    {(controlProps) => (
                      <Select value={field.value} onValueChange={field.onChange}>
                        <SelectTrigger className="w-full" {...controlProps}>
                          <SelectValue placeholder="Choose a shift" />
                        </SelectTrigger>
                        <SelectContent>
                          {(shifts.data?.data ?? []).map((s) => (
                            <SelectItem key={s.id} value={String(s.id)}>
                              {s.name}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    )}
                  </Field>
                )}
              />
              <Field label="Cycle starts" error={errors.anchor_date?.message} hint="Defaults to the first day.">
                <Input type="date" {...form.register('anchor_date')} />
              </Field>
            </div>
          )}
          <Field label="Reason" error={errors.reason?.message}>
            <Input placeholder="e.g. Covering for Sara" {...form.register('reason')} />
          </Field>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="temporary-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            Save
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
