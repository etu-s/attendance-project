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
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { applyServerErrors } from '@/lib/forms'
import { useSaveShift, type Shift } from './api'

const MAX = { day: 56, week: 8 } as const

// Mirrors ShiftRequest.php
const schema = z
  .object({
    name: z.string().trim().min(1, 'Enter a name.').max(120),
    code: z
      .string()
      .trim()
      .min(1, 'Enter a code.')
      .max(30)
      .regex(/^[A-Za-z0-9._-]+$/, 'Letters, numbers, dots, dashes or underscores only.'),
    cycle_unit: z.enum(['day', 'week']),
    cycle_length: z.string().regex(/^\d+$/, 'Enter a whole number.'),
    description: z.string().max(500),
    is_active: z.boolean(),
  })
  .superRefine((v, ctx) => {
    const n = Number(v.cycle_length)
    if (n < 1 || n > MAX[v.cycle_unit])
      ctx.addIssue({ code: 'custom', path: ['cycle_length'], message: `1 to ${String(MAX[v.cycle_unit])} ${v.cycle_unit}s.` })
  })

type Values = z.infer<typeof schema>

interface ShiftFormDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  shift?: Shift
  /** The cycle cannot change while a schedule uses the shift. */
  cycleLocked?: boolean
  onSaved?: (shift: Shift) => void
}

export function ShiftFormDialog({ open, onOpenChange, shift, cycleLocked = false, onSaved }: ShiftFormDialogProps) {
  const save = useSaveShift()
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { name: '', code: '', cycle_unit: 'week', cycle_length: '1', description: '', is_active: true },
  })
  const unit = useWatch({ control: form.control, name: 'cycle_unit' })

  useEffect(() => {
    if (open) {
      form.reset({
        name: shift?.name ?? '',
        code: shift?.code ?? '',
        cycle_unit: shift?.cycle_unit ?? 'week',
        cycle_length: String(shift?.cycle_length ?? 1),
        description: shift?.description ?? '',
        is_active: shift?.is_active ?? true,
      })
    }
  }, [open, shift, form])

  const submit = form.handleSubmit(async (v) => {
    try {
      const saved = await save.mutateAsync({
        id: shift?.id,
        payload: {
          name: v.name,
          code: v.code,
          description: v.description || null,
          is_active: v.is_active,
          ...(cycleLocked ? {} : { cycle_unit: v.cycle_unit, cycle_length: Number(v.cycle_length) }),
        },
      })
      toast.success(shift ? 'Shift saved.' : 'Shift created.')
      onOpenChange(false)
      onSaved?.(saved)
    } catch (error) {
      applyServerErrors(error, form.setError, ['name', 'code', 'cycle_unit', 'cycle_length', 'description', 'is_active'])
    }
  })

  const errors = form.formState.errors

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{shift ? 'Edit shift' : 'New shift'}</DialogTitle>
          <DialogDescription>A repeating cycle. Pick its working days and timetables next.</DialogDescription>
        </DialogHeader>
        <form id="shift-form" onSubmit={submit} className="grid gap-4" noValidate>
          <div className="grid gap-4 sm:grid-cols-[2fr_1fr]">
            <Field label="Name" error={errors.name?.message}>
              <Input {...form.register('name')} />
            </Field>
            <Field label="Code" error={errors.code?.message}>
              <Input className="font-mono" {...form.register('code')} />
            </Field>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Controller
              control={form.control}
              name="cycle_unit"
              render={({ field }) => (
                <Field
                  label="Repeats every"
                  error={errors.cycle_unit?.message}
                  hint={cycleLocked ? 'Used by a schedule; the cycle cannot change.' : undefined}
                >
                  {(controlProps) => (
                    <Select value={field.value} onValueChange={field.onChange} disabled={cycleLocked}>
                      <SelectTrigger className="w-full" {...controlProps}>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="week">Weeks (Mon–Sun)</SelectItem>
                        <SelectItem value="day">Days (rotation)</SelectItem>
                      </SelectContent>
                    </Select>
                  )}
                </Field>
              )}
            />
            <Field label={unit === 'week' ? 'Number of weeks' : 'Number of days'} error={errors.cycle_length?.message}>
              <Input inputMode="numeric" disabled={cycleLocked} {...form.register('cycle_length')} />
            </Field>
          </div>
          <Field label="Description" error={errors.description?.message}>
            <Textarea rows={2} {...form.register('description')} />
          </Field>
          <Controller
            control={form.control}
            name="is_active"
            render={({ field }) => (
              <div className="flex items-center justify-between rounded-lg border p-3">
                <div>
                  <Label htmlFor="shift-active">Active</Label>
                  <p className="text-muted-foreground text-xs">Inactive shifts cannot be scheduled.</p>
                </div>
                <Switch id="shift-active" checked={field.value} onCheckedChange={field.onChange} />
              </div>
            )}
          />
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form="shift-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            {shift ? 'Save changes' : 'Create shift'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
