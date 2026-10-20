import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2 } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Field } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { applyServerErrors } from '@/lib/forms'
import { useSavePlan, type Plan } from './api'

// Mirrors StorePlanRequest / UpdatePlanRequest.
const planSchema = z.object({
  name: z.string().trim().min(1, 'Enter a name.').max(100),
  slug: z
    .string()
    .trim()
    .min(1, 'Enter a slug.')
    .max(60)
    .regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/, 'Lowercase letters, numbers and single hyphens only.'),
  // Blank = unlimited.
  max_employees: z
    .string()
    .trim()
    .refine(
      (v) => v === '' || (/^\d+$/.test(v) && Number(v) >= 1 && Number(v) <= 1_000_000),
      'Enter a whole number from 1, or leave blank.',
    ),
  features: z.string().refine((v) => {
    const list = v
      .split(',')
      .map((f) => f.trim())
      .filter(Boolean)
    return list.length <= 50 && list.every((f) => f.length <= 50) && new Set(list).size === list.length
  }, 'Up to 50 unique features, 50 characters each.'),
  is_active: z.boolean(),
})

type PlanValues = z.infer<typeof planSchema>

const fields = ['name', 'slug', 'max_employees', 'features', 'is_active'] as const

function slugify(value: string): string {
  return value
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60)
}

export function PlanFormDialog({
  open,
  onOpenChange,
  plan,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  plan?: Plan
}) {
  const save = useSavePlan()
  const form = useForm<PlanValues>({
    resolver: zodResolver(planSchema),
    defaultValues: { name: '', slug: '', max_employees: '', features: '', is_active: true },
  })

  useEffect(() => {
    if (open) {
      form.reset({
        name: plan?.name ?? '',
        slug: plan?.slug ?? '',
        max_employees: plan?.max_employees == null ? '' : String(plan.max_employees),
        features: plan?.features.join(', ') ?? '',
        is_active: plan?.is_active ?? true,
      })
    }
  }, [open, plan, form])

  const submit = form.handleSubmit(async (values) => {
    try {
      await save.mutateAsync({
        id: plan?.id,
        payload: {
          name: values.name,
          slug: values.slug,
          max_employees: values.max_employees === '' ? null : Number(values.max_employees),
          features: values.features
            .split(',')
            .map((f) => f.trim())
            .filter(Boolean),
          is_active: values.is_active,
        },
      })
      toast.success(plan ? 'Plan updated.' : 'Plan created.')
      onOpenChange(false)
    } catch (error) {
      applyServerErrors(error, form.setError, fields)
    }
  })

  const errors = form.formState.errors

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{plan ? 'Edit plan' : 'New plan'}</DialogTitle>
          <DialogDescription>Plans set limits for the tenants assigned to them.</DialogDescription>
        </DialogHeader>
        <form id="plan-form" onSubmit={submit} className="grid gap-4" noValidate>
          <Field label="Name" error={errors.name?.message}>
            <Input
              {...form.register('name', {
                onChange: (event: React.ChangeEvent<HTMLInputElement>) => {
                  // Suggest a slug while creating, until the slug is edited by hand.
                  if (!plan && !form.formState.dirtyFields.slug) {
                    form.setValue('slug', slugify(event.target.value))
                  }
                },
              })}
            />
          </Field>
          <Field label="Slug" error={errors.slug?.message} hint="Stable identifier used in integrations.">
            <Input className="font-mono" {...form.register('slug')} />
          </Field>
          <Field label="Max employees" error={errors.max_employees?.message} hint="Leave blank for unlimited.">
            <Input inputMode="numeric" {...form.register('max_employees')} />
          </Field>
          <Field label="Features" error={errors.features?.message} hint="Comma-separated, e.g. attendance, leave, reports">
            <Input {...form.register('features')} />
          </Field>
          <Controller
            control={form.control}
            name="is_active"
            render={({ field }) => (
              <div className="flex items-center justify-between rounded-lg border p-3">
                <div>
                  <Label htmlFor="plan-active">Active</Label>
                  <p className="text-muted-foreground text-xs">Inactive plans cannot be assigned to new tenants.</p>
                </div>
                <Switch id="plan-active" checked={field.value} onCheckedChange={field.onChange} />
              </div>
            )}
          />
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form="plan-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            {plan ? 'Save changes' : 'Create plan'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
