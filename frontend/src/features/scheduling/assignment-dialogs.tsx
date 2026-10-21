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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useEmployees } from '@/features/employees/api'
import { useDepartments, useGroupOptions } from '@/features/organization/api'
import { useSession } from '@/features/tenant-auth/hooks'
import { useShifts } from '@/features/timetables/api'
import { todayIn } from '@/lib/format'
import { applyServerErrors } from '@/lib/forms'
import { targetLabels, useAssignSchedule, useUpdateAssignment, type ScheduleAssignment, type ScheduleTargetType } from './api'

const date = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Pick a date.')
const optionalDate = z.union([z.literal(''), date])

function ShiftSelect({ value, onChange, controlProps }: { value: string; onChange: (v: string) => void; controlProps: object }) {
  const shifts = useShifts({ is_active: 1, per_page: 100, sort: 'name' })

  return (
    <Select value={value} onValueChange={onChange}>
      <SelectTrigger className="w-full" {...controlProps}>
        <SelectValue placeholder={shifts.isPending ? 'Loading…' : 'Choose a shift'} />
      </SelectTrigger>
      <SelectContent>
        {(shifts.data?.data ?? []).map((s) => (
          <SelectItem key={s.id} value={String(s.id)}>
            {s.name} · {s.cycle_length} {s.cycle_unit}
            {s.cycle_length === 1 ? '' : 's'}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}

/** Department, group or employee picker for the chosen target level. */
function TargetSelect({
  type,
  value,
  onChange,
  controlProps,
}: {
  type: Exclude<ScheduleTargetType, 'organization'>
  value: string
  onChange: (v: string) => void
  controlProps: object
}) {
  const [search, setSearch] = useState('')
  const departments = useDepartments(true)
  const groups = useGroupOptions()
  const employees = useEmployees({ search, per_page: 50, sort: 'name', status: 'active' })

  const options =
    type === 'department'
      ? (departments.data ?? []).map((d) => ({ value: String(d.id), label: d.path ?? d.name }))
      : type === 'group'
        ? (groups.data?.data ?? []).map((g) => ({ value: String(g.id), label: `${g.name} (priority ${String(g.priority)})` }))
        : (employees.data?.data ?? []).map((e) => ({ value: String(e.id), label: `${e.full_name} · ${e.employee_code}` }))

  return (
    <div className="grid gap-2">
      {type === 'employee' && <SearchInput value={search} onChange={setSearch} label="Search employees" />}
      <Select value={value} onValueChange={onChange}>
        <SelectTrigger className="w-full" {...controlProps}>
          <SelectValue placeholder={`Choose a ${type}`} />
        </SelectTrigger>
        <SelectContent>
          {options.map((o) => (
            <SelectItem key={o.value} value={o.value}>
              {o.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  )
}

// Mirrors StoreScheduleAssignmentRequest (target existence and overlaps handled by the API).
const assignSchema = z
  .object({
    target_id: z.string(),
    shift_id: z.string().min(1, 'Choose a shift.'),
    effective_from: date,
    effective_to: optionalDate,
    anchor_date: optionalDate,
  })
  .superRefine((v, ctx) => {
    if (v.effective_to !== '' && v.effective_to < v.effective_from)
      ctx.addIssue({ code: 'custom', path: ['effective_to'], message: 'Ends before it starts.' })
  })

type AssignValues = z.infer<typeof assignSchema>

export function AssignDialog({ type, open, onClose }: { type: ScheduleTargetType; open: boolean; onClose: () => void }) {
  const { workspace } = useSession()
  const assign = useAssignSchedule()
  const today = todayIn(workspace.timezone)
  const form = useForm<AssignValues>({
    resolver: zodResolver(assignSchema),
    defaultValues: { target_id: '', shift_id: '', effective_from: today, effective_to: '', anchor_date: '' },
  })

  useEffect(() => {
    if (open) form.reset({ target_id: '', shift_id: '', effective_from: today, effective_to: '', anchor_date: '' })
  }, [open, today, form])

  const submit = form.handleSubmit(async (v) => {
    if (type !== 'organization' && v.target_id === '') {
      form.setError('target_id', { message: `Choose a ${type}.` })
      return
    }
    try {
      await assign.mutateAsync({
        target_type: type,
        target_id: type === 'organization' ? null : Number(v.target_id),
        shift_id: Number(v.shift_id),
        effective_from: v.effective_from,
        effective_to: v.effective_to || null,
        anchor_date: v.anchor_date || null,
      })
      toast.success('Schedule assigned.')
      onClose()
    } catch (error) {
      applyServerErrors(error, form.setError, ['target_id', 'shift_id', 'effective_from', 'effective_to', 'anchor_date'])
    }
  })

  const errors = form.formState.errors

  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Assign shift · {targetLabels[type]}</DialogTitle>
          <DialogDescription>
            With an end date this replaces whatever applied in that range. Without one it runs until the next planned change.
          </DialogDescription>
        </DialogHeader>
        <form id="assign-form" onSubmit={submit} className="grid gap-4" noValidate>
          {type !== 'organization' && (
            <Controller
              control={form.control}
              name="target_id"
              render={({ field }) => (
                <Field label={targetLabels[type]} error={errors.target_id?.message}>
                  {(controlProps) => (
                    <TargetSelect type={type} value={field.value} onChange={field.onChange} controlProps={controlProps} />
                  )}
                </Field>
              )}
            />
          )}
          <Controller
            control={form.control}
            name="shift_id"
            render={({ field }) => (
              <Field label="Shift" error={errors.shift_id?.message}>
                {(controlProps) => <ShiftSelect value={field.value} onChange={field.onChange} controlProps={controlProps} />}
              </Field>
            )}
          />
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="From" error={errors.effective_from?.message}>
              <Input type="date" {...form.register('effective_from')} />
            </Field>
            <Field label="Until (optional)" error={errors.effective_to?.message}>
              <Input type="date" {...form.register('effective_to')} />
            </Field>
            <Field label="Cycle starts" error={errors.anchor_date?.message} hint="Defaults to From.">
              <Input type="date" {...form.register('anchor_date')} />
            </Field>
          </div>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="assign-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            Assign
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

// Mirrors UpdateScheduleAssignmentRequest.
const editSchema = z.object({ shift_id: z.string().min(1, 'Choose a shift.'), anchor_date: date, effective_to: optionalDate })
type EditValues = z.infer<typeof editSchema>

export function EditAssignmentDialog({ assignment, onClose }: { assignment: ScheduleAssignment | null; onClose: () => void }) {
  const update = useUpdateAssignment()
  const form = useForm<EditValues>({
    resolver: zodResolver(editSchema),
    defaultValues: { shift_id: '', anchor_date: '', effective_to: '' },
  })

  useEffect(() => {
    if (assignment) {
      form.reset({
        shift_id: String(assignment.shift.id),
        anchor_date: assignment.anchor_date,
        effective_to: assignment.effective_to ?? '',
      })
    }
  }, [assignment, form])

  const submit = form.handleSubmit(async (v) => {
    if (!assignment) return
    try {
      await update.mutateAsync({
        id: assignment.id,
        payload: { shift_id: Number(v.shift_id), anchor_date: v.anchor_date, effective_to: v.effective_to || null },
      })
      toast.success('Schedule updated.')
      onClose()
    } catch (error) {
      applyServerErrors(error, form.setError, ['shift_id', 'anchor_date', 'effective_to'])
    }
  })

  const errors = form.formState.errors

  return (
    <Dialog open={assignment !== null} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Edit schedule</DialogTitle>
          <DialogDescription>
            {assignment?.target.name ?? ''} from {assignment?.effective_from}.
          </DialogDescription>
        </DialogHeader>
        <form id="edit-assignment-form" onSubmit={submit} className="grid gap-4" noValidate>
          <Controller
            control={form.control}
            name="shift_id"
            render={({ field }) => (
              <Field label="Shift" error={errors.shift_id?.message}>
                {(controlProps) => <ShiftSelect value={field.value} onChange={field.onChange} controlProps={controlProps} />}
              </Field>
            )}
          />
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Cycle starts" error={errors.anchor_date?.message}>
              <Input type="date" {...form.register('anchor_date')} />
            </Field>
            <Field label="Until (empty = open)" error={errors.effective_to?.message}>
              <Input type="date" {...form.register('effective_to')} />
            </Field>
          </div>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="edit-assignment-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            Save
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
