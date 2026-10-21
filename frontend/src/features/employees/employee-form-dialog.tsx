import { useEffect } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2 } from 'lucide-react'
import { Controller, useForm, useWatch, type Control, type FieldValues, type Path } from 'react-hook-form'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { Field } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Separator } from '@/components/ui/separator'
import { useDepartments, useGroupOptions, useLocationOptions } from '@/features/organization/api'
import { useSession } from '@/features/tenant-auth/hooks'
import { applyServerErrors } from '@/lib/forms'
import { todayIn } from '@/lib/format'
import { employmentTypeLabels, employmentTypes, useCreateEmployee, useUpdateEmployee, type Employee } from './api'
import { createEmployeeSchema, updateEmployeeSchema, type CreateEmployeeValues, type UpdateEmployeeValues } from './schemas'

const NONE = 'none'

function ProfileFields<T extends FieldValues>({
  control,
  register,
  errors,
}: {
  control: Control<T>
  register: (name: Path<T>) => object
  errors: Partial<Record<string, { message?: string }>>
}) {
  return (
    <>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="First name" error={errors.first_name?.message}>
          <Input autoComplete="off" {...register('first_name' as Path<T>)} />
        </Field>
        <Field label="Last name" error={errors.last_name?.message}>
          <Input autoComplete="off" {...register('last_name' as Path<T>)} />
        </Field>
        <Field label="Employee code" error={errors.employee_code?.message} hint="The identifier punches will arrive with.">
          <Input className="font-mono" autoComplete="off" {...register('employee_code' as Path<T>)} />
        </Field>
        <Field label="Hire date" error={errors.hire_date?.message}>
          <Input type="date" {...register('hire_date' as Path<T>)} />
        </Field>
        <Field label="Email" error={errors.email?.message}>
          <Input type="email" autoComplete="off" {...register('email' as Path<T>)} />
        </Field>
        <Field label="Phone" error={errors.phone?.message}>
          <Input type="tel" autoComplete="off" {...register('phone' as Path<T>)} />
        </Field>
        <Field label="Job title" error={errors.job_title?.message}>
          <Input {...register('job_title' as Path<T>)} />
        </Field>
        <Controller
          control={control}
          name={'employment_type' as Path<T>}
          render={({ field }) => (
            <Field label="Employment type" error={errors.employment_type?.message}>
              {(controlProps) => (
                <Select value={String(field.value)} onValueChange={field.onChange}>
                  <SelectTrigger className="w-full" {...controlProps}>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {employmentTypes.map((type) => (
                      <SelectItem key={type} value={type}>
                        {employmentTypeLabels[type]}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </Field>
          )}
        />
      </div>
    </>
  )
}

export function CreateEmployeeDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const { workspace } = useSession()
  const navigate = useNavigate()
  const create = useCreateEmployee()
  const departments = useDepartments(true)
  const locations = useLocationOptions()
  const groups = useGroupOptions()

  const form = useForm<CreateEmployeeValues>({
    resolver: zodResolver(createEmployeeSchema),
    defaultValues: {
      employee_code: '',
      first_name: '',
      last_name: '',
      email: '',
      phone: '',
      job_title: '',
      employment_type: 'full_time',
      hire_date: todayIn(workspace.timezone),
      department_id: '',
      location_id: NONE,
      group_ids: [],
      create_login: false,
      login_password: '',
    },
  })

  useEffect(() => {
    if (open) form.reset()
  }, [open, form])

  const createLogin = useWatch({ control: form.control, name: 'create_login' })

  const submit = form.handleSubmit(async (v) => {
    try {
      const employee = await create.mutateAsync({
        employee_code: v.employee_code,
        first_name: v.first_name,
        last_name: v.last_name,
        email: v.email || null,
        phone: v.phone || null,
        job_title: v.job_title || null,
        employment_type: v.employment_type,
        hire_date: v.hire_date,
        department_id: Number(v.department_id),
        location_id: v.location_id === NONE ? null : Number(v.location_id),
        group_ids: v.group_ids,
        create_login: v.create_login,
        ...(v.create_login ? { login_password: v.login_password } : {}),
      })
      toast.success(`${employee.full_name} added.`)
      onOpenChange(false)
      void navigate(`/employees/${String(employee.id)}`)
    } catch (error) {
      applyServerErrors(error, form.setError, [
        'employee_code',
        'first_name',
        'last_name',
        'email',
        'phone',
        'job_title',
        'employment_type',
        'hire_date',
        'department_id',
        'location_id',
        'group_ids',
        'login_password',
      ])
    }
  })

  const errors = form.formState.errors

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[92svh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Add employee</DialogTitle>
          <DialogDescription>Department, location and groups start on the hire date.</DialogDescription>
        </DialogHeader>
        <form id="employee-form" onSubmit={submit} className="grid gap-4" noValidate>
          <ProfileFields control={form.control} register={(name) => form.register(name)} errors={errors} />

          <Separator />

          <div className="grid gap-4 sm:grid-cols-2">
            <Controller
              control={form.control}
              name="department_id"
              render={({ field }) => (
                <Field label="Department" error={errors.department_id?.message}>
                  {(controlProps) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger className="w-full" {...controlProps}>
                        <SelectValue placeholder={departments.isPending ? 'Loading…' : 'Choose a department'} />
                      </SelectTrigger>
                      <SelectContent>
                        {(departments.data ?? []).map((d) => (
                          <SelectItem key={d.id} value={String(d.id)}>
                            {d.path ?? d.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                </Field>
              )}
            />
            <Controller
              control={form.control}
              name="location_id"
              render={({ field }) => (
                <Field label="Location" error={errors.location_id?.message}>
                  {(controlProps) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger className="w-full" {...controlProps}>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value={NONE}>No location</SelectItem>
                        {(locations.data?.data ?? []).map((l) => (
                          <SelectItem key={l.id} value={String(l.id)}>
                            {l.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                </Field>
              )}
            />
          </div>

          {(groups.data?.data ?? []).length > 0 && (
            <Controller
              control={form.control}
              name="group_ids"
              render={({ field }) => (
                <fieldset className="grid gap-2">
                  <legend className="mb-1.5 text-sm font-medium">Groups</legend>
                  <div className="flex flex-wrap gap-x-5 gap-y-2">
                    {(groups.data?.data ?? []).map((g) => (
                      <label key={g.id} className="flex items-center gap-2 text-sm">
                        <Checkbox
                          checked={field.value.includes(g.id)}
                          onCheckedChange={(checked) =>
                            field.onChange(checked === true ? [...field.value, g.id] : field.value.filter((id) => id !== g.id))
                          }
                        />
                        {g.name}
                      </label>
                    ))}
                  </div>
                </fieldset>
              )}
            />
          )}

          <Separator />

          <Controller
            control={form.control}
            name="create_login"
            render={({ field }) => (
              <label className="flex items-start gap-3">
                <Checkbox
                  className="mt-0.5"
                  checked={field.value}
                  onCheckedChange={(checked) => field.onChange(checked === true)}
                />
                <span>
                  <span className="block text-sm font-medium">Create a login</span>
                  <span className="text-muted-foreground block text-xs">
                    Lets them sign in with their email to see their own profile (Employee role).
                  </span>
                </span>
              </label>
            )}
          />
          {createLogin && (
            <Field
              label="Initial password"
              error={errors.login_password?.message}
              hint="At least 8 characters with letters and numbers."
            >
              <Input type="password" autoComplete="new-password" {...form.register('login_password')} />
            </Field>
          )}
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form="employee-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            Add employee
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export function EditEmployeeDialog({
  employee,
  onOpenChange,
}: {
  employee: Employee | null
  onOpenChange: (open: boolean) => void
}) {
  const update = useUpdateEmployee(employee?.id ?? 0)
  const form = useForm<UpdateEmployeeValues>({
    resolver: zodResolver(updateEmployeeSchema),
    defaultValues: {
      employee_code: '',
      first_name: '',
      last_name: '',
      email: '',
      phone: '',
      job_title: '',
      employment_type: 'full_time',
      hire_date: '',
      status: 'active',
    },
  })

  useEffect(() => {
    if (employee) {
      form.reset({
        employee_code: employee.employee_code,
        first_name: employee.first_name,
        last_name: employee.last_name,
        email: employee.email ?? '',
        phone: employee.phone ?? '',
        job_title: employee.job_title ?? '',
        employment_type: employee.employment_type,
        hire_date: employee.hire_date,
        status: employee.status === 'inactive' ? 'inactive' : 'active',
      })
    }
  }, [employee, form])

  const terminated = employee?.status === 'terminated'

  const submit = form.handleSubmit(async (v) => {
    try {
      await update.mutateAsync({
        employee_code: v.employee_code,
        first_name: v.first_name,
        last_name: v.last_name,
        email: v.email || null,
        phone: v.phone || null,
        job_title: v.job_title || null,
        employment_type: v.employment_type,
        hire_date: v.hire_date,
        ...(terminated ? {} : { status: v.status }),
      })
      toast.success('Employee updated.')
      onOpenChange(false)
    } catch (error) {
      applyServerErrors(error, form.setError, [
        'employee_code',
        'first_name',
        'last_name',
        'email',
        'phone',
        'job_title',
        'employment_type',
        'hire_date',
        'status',
      ])
    }
  })

  return (
    <Dialog open={employee !== null} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[92svh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Edit employee</DialogTitle>
          <DialogDescription>
            Department and location changes are dated: use Transfer. A corrected hire date moves the start of their history.
          </DialogDescription>
        </DialogHeader>
        <form id="edit-employee-form" onSubmit={submit} className="grid gap-4" noValidate>
          <ProfileFields control={form.control} register={(name) => form.register(name)} errors={form.formState.errors} />
          {!terminated && (
            <Controller
              control={form.control}
              name="status"
              render={({ field }) => (
                <div className="flex items-center justify-between rounded-lg border p-3">
                  <div>
                    <Label htmlFor="employee-inactive">On hold (inactive)</Label>
                    <p className="text-muted-foreground text-xs">Keeps their history and still counts toward the plan limit.</p>
                  </div>
                  <Checkbox
                    id="employee-inactive"
                    checked={field.value === 'inactive'}
                    onCheckedChange={(checked) => field.onChange(checked === true ? 'inactive' : 'active')}
                  />
                </div>
              )}
            />
          )}
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form="edit-employee-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            Save changes
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
