import { useEffect, useMemo, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2, MoreHorizontal, Pencil, Plus, Trash2, UserCog } from 'lucide-react'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { Field } from '@/components/form-field'
import { EmptyState, ErrorState, PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useCan } from '@/features/tenant-auth/hooks'
import { applyServerErrors, errorMessage } from '@/lib/forms'
import { useDeleteDepartment, useDepartments, useSaveDepartment, type Department } from './api'
import { ManagersDialog } from './managers-dialog'

const NONE = 'none'

// Mirrors DepartmentRequest.php
const schema = z.object({
  name: z.string().trim().min(1, 'Enter a name.').max(120),
  code: z
    .string()
    .trim()
    .min(1, 'Enter a code.')
    .max(30)
    .regex(/^[A-Za-z0-9._-]+$/, 'Letters, numbers, dots, dashes or underscores only.'),
  parent_id: z.string(),
  is_active: z.boolean(),
})

type Values = z.infer<typeof schema>

/** Ids of a department and everything below it (cannot become its parent). */
function subtreeIds(departments: Department[], rootId: number): Set<number> {
  const ids = new Set([rootId])
  let grew = true
  while (grew) {
    grew = false
    for (const d of departments) {
      if (d.parent_id !== null && ids.has(d.parent_id) && !ids.has(d.id)) {
        ids.add(d.id)
        grew = true
      }
    }
  }
  return ids
}

function DepartmentFormDialog({
  open,
  onOpenChange,
  department,
  departments,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  department?: Department
  departments: Department[]
}) {
  const save = useSaveDepartment()
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { name: '', code: '', parent_id: NONE, is_active: true },
  })

  useEffect(() => {
    if (open) {
      form.reset({
        name: department?.name ?? '',
        code: department?.code ?? '',
        parent_id: department?.parent_id ? String(department.parent_id) : NONE,
        is_active: department?.is_active ?? true,
      })
    }
  }, [open, department, form])

  const excluded = useMemo(
    () => (department ? subtreeIds(departments, department.id) : new Set<number>()),
    [department, departments],
  )

  const submit = form.handleSubmit(async (values) => {
    try {
      await save.mutateAsync({
        id: department?.id,
        payload: { ...values, parent_id: values.parent_id === NONE ? null : Number(values.parent_id) },
      })
      toast.success(department ? 'Department updated.' : 'Department created.')
      onOpenChange(false)
    } catch (error) {
      applyServerErrors(error, form.setError, ['name', 'code', 'parent_id', 'is_active'])
    }
  })

  const errors = form.formState.errors

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{department ? 'Edit department' : 'New department'}</DialogTitle>
          <DialogDescription>Departments can be nested; managers of a department also see its sub-departments.</DialogDescription>
        </DialogHeader>
        <form id="department-form" onSubmit={submit} className="grid gap-4" noValidate>
          <div className="grid gap-4 sm:grid-cols-[2fr_1fr]">
            <Field label="Name" error={errors.name?.message}>
              <Input {...form.register('name')} />
            </Field>
            <Field label="Code" error={errors.code?.message}>
              <Input className="font-mono" {...form.register('code')} />
            </Field>
          </div>
          <Controller
            control={form.control}
            name="parent_id"
            render={({ field }) => (
              <Field label="Parent department" error={errors.parent_id?.message}>
                {(controlProps) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger className="w-full" {...controlProps}>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={NONE}>None (top level)</SelectItem>
                      {departments
                        .filter((d) => !excluded.has(d.id))
                        .map((d) => (
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
            name="is_active"
            render={({ field }) => (
              <div className="flex items-center justify-between rounded-lg border p-3">
                <div>
                  <Label htmlFor="department-active">Active</Label>
                  <p className="text-muted-foreground text-xs">Inactive departments cannot receive new employees.</p>
                </div>
                <Switch id="department-active" checked={field.value} onCheckedChange={field.onChange} />
              </div>
            )}
          />
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form="department-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            {department ? 'Save changes' : 'Create department'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export function DepartmentsPage() {
  const can = useCan()
  const manage = can('departments.manage')
  const departments = useDepartments()
  const remove = useDeleteDepartment()
  const [creating, setCreating] = useState(false)
  const [editing, setEditing] = useState<Department | null>(null)
  const [managing, setManaging] = useState<Department | null>(null)
  const [deleting, setDeleting] = useState<Department | null>(null)
  const rows = departments.data ?? []

  return (
    <>
      <PageHeader
        title="Departments"
        description="Your organization's structure. Every employee belongs to one department at a time."
        actions={
          manage && (
            <Button onClick={() => setCreating(true)}>
              <Plus /> New department
            </Button>
          )
        }
      />

      {departments.isError ? (
        <ErrorState error={departments.error} onRetry={() => departments.refetch()} />
      ) : !departments.isPending && rows.length === 0 ? (
        <EmptyState title="No departments yet" description="Create your first department to start adding employees." />
      ) : (
        <div className="overflow-x-auto rounded-lg border">
          <Table>
            <caption className="sr-only">Departments</caption>
            <TableHeader>
              <TableRow className="hover:bg-transparent">
                <TableHead>Department</TableHead>
                <TableHead className="hidden md:table-cell">Managers</TableHead>
                <TableHead className="text-right">Employees</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-12" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {departments.isPending
                ? Array.from({ length: 5 }, (_, i) => (
                    <TableRow key={i}>
                      <TableCell colSpan={5}>
                        <Skeleton className="h-4 w-64" />
                      </TableCell>
                    </TableRow>
                  ))
                : rows.map((d) => (
                    <TableRow key={d.id}>
                      <TableCell>
                        <div style={{ paddingLeft: `${(d.depth ?? 0) * 1.25}rem` }} className="flex items-center gap-2">
                          {(d.depth ?? 0) > 0 && (
                            <span className="text-muted-foreground" aria-hidden>
                              └
                            </span>
                          )}
                          <div>
                            <p className="font-medium">{d.name}</p>
                            <p className="text-muted-foreground font-mono text-xs">{d.code}</p>
                          </div>
                        </div>
                      </TableCell>
                      <TableCell className="text-muted-foreground hidden text-sm md:table-cell">
                        {(d.managers ?? []).map((m) => m.name).join(', ') || '—'}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">{d.employees_count ?? 0}</TableCell>
                      <TableCell>
                        <StatusBadge tone={d.is_active ? 'success' : 'neutral'}>
                          {d.is_active ? 'active' : 'inactive'}
                        </StatusBadge>
                      </TableCell>
                      <TableCell className="text-right">
                        {manage && (
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button variant="ghost" size="icon" className="size-8" aria-label={`Actions for ${d.name}`}>
                                <MoreHorizontal />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                              <DropdownMenuItem onSelect={() => setEditing(d)}>
                                <Pencil /> Edit
                              </DropdownMenuItem>
                              <DropdownMenuItem onSelect={() => setManaging(d)}>
                                <UserCog /> Managers
                              </DropdownMenuItem>
                              <DropdownMenuSeparator />
                              <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(d)}>
                                <Trash2 /> Delete
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
            </TableBody>
          </Table>
        </div>
      )}

      <DepartmentFormDialog open={creating} onOpenChange={setCreating} departments={rows} />
      <DepartmentFormDialog
        open={editing !== null}
        onOpenChange={(open) => !open && setEditing(null)}
        department={editing ?? undefined}
        departments={rows}
      />
      <ManagersDialog kind="departments" target={managing} onOpenChange={(open) => !open && setManaging(null)} />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={`Delete ${deleting?.name ?? 'department'}?`}
        description={<p>Departments with sub-departments or employee history cannot be deleted; deactivate them instead.</p>}
        confirmLabel="Delete department"
        destructive
        pending={remove.isPending}
        onConfirm={() => {
          if (!deleting) return
          remove.mutate(deleting.id, {
            onSuccess: () => {
              toast.success('Department deleted.')
              setDeleting(null)
            },
            onError: (e) => toast.error(errorMessage(e)),
          })
        }}
      />
    </>
  )
}
