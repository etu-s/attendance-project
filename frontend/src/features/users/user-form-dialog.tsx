import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2 } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { Field } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { applyServerErrors } from '@/lib/forms'
import { useCreateUser, useUpdateUser } from './api'
import { createUserSchema, updateUserSchema, type UpdateUserValues } from './schemas'
import { roleLabels, type Role, type User } from './types'

interface UserFormDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Present = edit, absent = create. */
  user?: User
  assignableRoles: Role[]
  /** Editing yourself: role and status are locked (the API refuses those changes). */
  isSelf?: boolean
}

const fields = ['name', 'email', 'password', 'role', 'status'] as const

export function UserFormDialog({ open, onOpenChange, user, assignableRoles, isSelf = false }: UserFormDialogProps) {
  const editing = user !== undefined
  const createUser = useCreateUser()
  const updateUser = useUpdateUser()

  // One form type for both modes; the resolver switches the password rule.
  const form = useForm<UpdateUserValues>({
    resolver: zodResolver(editing ? updateUserSchema : createUserSchema),
    defaultValues: { name: '', email: '', password: '', role: 'employee', status: 'active' },
  })

  // Reset only when the dialog opens or targets another user: a new
  // assignableRoles array on every parent render must not wipe typed input.
  const defaultRole = assignableRoles.at(-1) ?? 'employee'
  useEffect(() => {
    if (open) {
      form.reset({
        name: user?.name ?? '',
        email: user?.email ?? '',
        password: '',
        role: user?.role ?? defaultRole,
        status: user?.status ?? 'active',
      })
    }
  }, [open, user, defaultRole, form])

  const submit = form.handleSubmit(async (values) => {
    try {
      if (editing) {
        const payload: Partial<UpdateUserValues> = { name: values.name, email: values.email }
        if (values.password) payload.password = values.password
        if (!isSelf) {
          payload.role = values.role
          payload.status = values.status
        }
        await updateUser.mutateAsync({ id: user.id, payload })
        toast.success('User updated.')
      } else {
        await createUser.mutateAsync({ ...values, password: values.password })
        toast.success('User created.')
      }
      onOpenChange(false)
    } catch (error) {
      applyServerErrors(error, form.setError, fields)
    }
  })

  const errors = form.formState.errors
  // A role the actor may not assign (e.g. an HR manager viewing an admin) is shown but not selectable.
  const roleOptions = user?.role && !assignableRoles.includes(user.role) ? [user.role, ...assignableRoles] : assignableRoles

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{editing ? 'Edit user' : 'Add user'}</DialogTitle>
          <DialogDescription>
            {editing ? 'Update account details and access.' : 'Create a login for someone in this workspace.'}
          </DialogDescription>
        </DialogHeader>

        <form id="user-form" onSubmit={submit} className="grid gap-4" noValidate>
          <Field label="Full name" error={errors.name?.message}>
            <Input autoComplete="off" {...form.register('name')} />
          </Field>
          <Field label="Email" error={errors.email?.message}>
            <Input type="email" autoComplete="off" {...form.register('email')} />
          </Field>
          <Field
            label={editing ? 'New password' : 'Password'}
            error={errors.password?.message}
            hint={editing ? 'Leave empty to keep the current password.' : 'At least 8 characters with letters and numbers.'}
          >
            <Input type="password" autoComplete="new-password" {...form.register('password')} />
          </Field>

          <div className="grid gap-4 sm:grid-cols-2">
            <Controller
              control={form.control}
              name="role"
              render={({ field }) => (
                <Field label="Role" error={errors.role?.message} hint={isSelf ? 'You cannot change your own role.' : undefined}>
                  {(controlProps) => (
                    <Select value={field.value} onValueChange={field.onChange} disabled={isSelf}>
                      <SelectTrigger className="w-full" {...controlProps}>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {roleOptions.map((role) => (
                          <SelectItem key={role} value={role} disabled={!assignableRoles.includes(role)}>
                            {roleLabels[role]}
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
              name="status"
              render={({ field }) => (
                <Field label="Status" error={errors.status?.message}>
                  {(controlProps) => (
                    <Select value={field.value} onValueChange={field.onChange} disabled={isSelf}>
                      <SelectTrigger className="w-full" {...controlProps}>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="active">Active</SelectItem>
                        <SelectItem value="inactive">Inactive (cannot sign in)</SelectItem>
                      </SelectContent>
                    </Select>
                  )}
                </Field>
              )}
            />
          </div>
        </form>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form="user-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            {editing ? 'Save changes' : 'Create user'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
