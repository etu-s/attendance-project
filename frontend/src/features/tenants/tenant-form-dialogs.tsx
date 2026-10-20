import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2 } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm, type Control, type FieldValues, type Path } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Field } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Separator } from '@/components/ui/separator'
import { useActivePlans } from '@/features/plans/api'
import { applyServerErrors } from '@/lib/forms'
import { tenantHostSuffix } from '@/lib/host'
import { useCreateTenant, useUpdateTenant, type Tenant } from './api'

const reserved = ['www', 'api', 'app', 'admin', 'central', 'mail', 'static', 'assets', 'status']

const password = z
  .string()
  .min(8, 'Use at least 8 characters.')
  .regex(/\p{L}/u, 'Include at least one letter.')
  .regex(/\d/, 'Include at least one number.')

// Mirrors StoreTenantRequest (uniqueness and plan availability are checked by the API).
const createSchema = z
  .object({
    name: z.string().trim().min(1, 'Enter the organization name.').max(120),
    subdomain: z
      .string()
      .trim()
      .toLowerCase()
      .min(2, 'Use at least 2 characters.')
      .max(63)
      .regex(/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])$/, 'Lowercase letters, numbers and inner hyphens only.')
      .refine((value) => !reserved.includes(value), 'This subdomain is reserved.'),
    plan_id: z.string().min(1, 'Choose a plan.'),
    timezone: z.string().min(1, 'Enter a timezone.'),
    admin_name: z.string().trim().min(1, 'Enter the admin name.').max(120),
    admin_email: z.email('Enter a valid email address.').max(255),
    admin_password: password,
    admin_password_confirmation: z.string(),
  })
  .refine((v) => v.admin_password === v.admin_password_confirmation, {
    path: ['admin_password_confirmation'],
    message: 'Passwords do not match.',
  })

type CreateValues = z.infer<typeof createSchema>

const editSchema = z.object({
  name: z.string().trim().min(1, 'Enter the organization name.').max(120),
  plan_id: z.string().min(1, 'Choose a plan.'),
})

type EditValues = z.infer<typeof editSchema>

const timezones: string[] = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : []

function PlanSelect<T extends FieldValues>({ control, name, error }: { control: Control<T>; name: Path<T>; error?: string }) {
  const plans = useActivePlans()

  return (
    <Controller
      control={control}
      name={name}
      render={({ field }) => (
        <Field label="Plan" error={error ?? (plans.isError ? 'Could not load plans.' : undefined)}>
          {(controlProps) => (
            <Select value={field.value} onValueChange={field.onChange} disabled={plans.isPending}>
              <SelectTrigger className="w-full" {...controlProps}>
                <SelectValue placeholder={plans.isPending ? 'Loading plans…' : 'Choose a plan'} />
              </SelectTrigger>
              <SelectContent>
                {(plans.data?.data ?? []).map((plan) => (
                  <SelectItem key={plan.id} value={String(plan.id)}>
                    {plan.name} · {plan.max_employees ?? 'unlimited'} employees
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </Field>
      )}
    />
  )
}

export function CreateTenantDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const create = useCreateTenant()
  const form = useForm<CreateValues>({
    resolver: zodResolver(createSchema),
    defaultValues: {
      name: '',
      subdomain: '',
      plan_id: '',
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
      admin_name: '',
      admin_email: '',
      admin_password: '',
      admin_password_confirmation: '',
    },
  })

  useEffect(() => {
    if (open) form.reset()
  }, [open, form])

  const submit = form.handleSubmit(async (values) => {
    try {
      const tenant = await create.mutateAsync({ ...values, plan_id: Number(values.plan_id) })
      toast.success(`${tenant.name} is ready at ${tenant.subdomain}${tenantHostSuffix()}`)
      onOpenChange(false)
    } catch (error) {
      applyServerErrors(error, form.setError, [
        'name',
        'subdomain',
        'plan_id',
        'timezone',
        'admin_name',
        'admin_email',
        'admin_password',
        'admin_password_confirmation',
      ])
    }
  })

  const errors = form.formState.errors

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>New tenant</DialogTitle>
          <DialogDescription>Creates the workspace, its database and its first Tenant Admin.</DialogDescription>
        </DialogHeader>
        <form id="tenant-form" onSubmit={submit} className="grid gap-4" noValidate>
          <Field label="Organization name" error={errors.name?.message}>
            <Input {...form.register('name')} />
          </Field>
          <Field label="Subdomain" error={errors.subdomain?.message} hint="Cannot be changed later.">
            {(controlProps) => (
              <div className="flex items-center gap-2">
                <Input
                  className="font-mono"
                  autoCapitalize="none"
                  spellCheck={false}
                  {...controlProps}
                  {...form.register('subdomain')}
                />
                <span className="text-muted-foreground shrink-0 font-mono text-sm">{tenantHostSuffix()}</span>
              </div>
            )}
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <PlanSelect control={form.control} name="plan_id" error={errors.plan_id?.message} />
            <Field label="Timezone" error={errors.timezone?.message}>
              <Input list="tenant-timezones" {...form.register('timezone')} />
            </Field>
            <datalist id="tenant-timezones">
              {timezones.map((zone) => (
                <option key={zone} value={zone} />
              ))}
            </datalist>
          </div>

          <Separator />
          <p className="text-sm font-medium">First Tenant Admin</p>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Name" error={errors.admin_name?.message}>
              <Input autoComplete="off" {...form.register('admin_name')} />
            </Field>
            <Field label="Email" error={errors.admin_email?.message}>
              <Input type="email" autoComplete="off" {...form.register('admin_email')} />
            </Field>
            <Field label="Password" error={errors.admin_password?.message}>
              <Input type="password" autoComplete="new-password" {...form.register('admin_password')} />
            </Field>
            <Field label="Confirm password" error={errors.admin_password_confirmation?.message}>
              <Input type="password" autoComplete="new-password" {...form.register('admin_password_confirmation')} />
            </Field>
          </div>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form="tenant-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            {form.formState.isSubmitting ? 'Provisioning…' : 'Create tenant'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export function EditTenantDialog({ tenant, onOpenChange }: { tenant: Tenant | null; onOpenChange: (open: boolean) => void }) {
  const update = useUpdateTenant()
  const form = useForm<EditValues>({ resolver: zodResolver(editSchema), defaultValues: { name: '', plan_id: '' } })

  useEffect(() => {
    if (tenant) form.reset({ name: tenant.name, plan_id: String(tenant.plan.id) })
  }, [tenant, form])

  const submit = form.handleSubmit(async (values) => {
    if (!tenant) return
    try {
      await update.mutateAsync({ id: tenant.id, payload: { name: values.name, plan_id: Number(values.plan_id) } })
      toast.success('Tenant updated.')
      onOpenChange(false)
    } catch (error) {
      applyServerErrors(error, form.setError, ['name', 'plan_id'])
    }
  })

  return (
    <Dialog open={tenant !== null} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Edit tenant</DialogTitle>
          <DialogDescription>
            Subdomain <span className="font-mono">{tenant?.subdomain}</span> cannot be changed.
          </DialogDescription>
        </DialogHeader>
        <form id="edit-tenant-form" onSubmit={submit} className="grid gap-4" noValidate>
          <Field label="Organization name" error={form.formState.errors.name?.message}>
            <Input {...form.register('name')} />
          </Field>
          <PlanSelect control={form.control} name="plan_id" error={form.formState.errors.plan_id?.message} />
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form="edit-tenant-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            Save changes
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
