import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2, MoreHorizontal, Pencil, Plus, Trash2, UserCog, Users } from 'lucide-react'
import { Controller, useForm } from 'react-hook-form'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { z } from 'zod'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { DataTable, type Column } from '@/components/data-table'
import { Field } from '@/components/form-field'
import { SearchInput } from '@/components/search-input'
import { PageHeader } from '@/components/states'
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
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { useCan } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { applyServerErrors, errorMessage } from '@/lib/forms'
import { useDeleteGroup, useGroups, useSaveGroup, type Group } from './api'
import { ManagersDialog } from './managers-dialog'

// Mirrors GroupRequest.php
const schema = z.object({
  name: z.string().trim().min(1, 'Enter a name.').max(120),
  code: z
    .string()
    .trim()
    .min(1, 'Enter a code.')
    .max(30)
    .regex(/^[A-Za-z0-9._-]+$/, 'Letters, numbers, dots, dashes or underscores only.'),
  priority: z
    .string()
    .trim()
    .refine((v) => /^\d+$/.test(v) && Number(v) >= 1 && Number(v) <= 1_000_000, 'Enter a whole number from 1.'),
  description: z.string().max(500),
  is_active: z.boolean(),
})

type Values = z.infer<typeof schema>

function GroupFormDialog({ open, onOpenChange, group }: { open: boolean; onOpenChange: (open: boolean) => void; group?: Group }) {
  const save = useSaveGroup()
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { name: '', code: '', priority: '', description: '', is_active: true },
  })

  useEffect(() => {
    if (open) {
      form.reset({
        name: group?.name ?? '',
        code: group?.code ?? '',
        priority: group ? String(group.priority) : '',
        description: group?.description ?? '',
        is_active: group?.is_active ?? true,
      })
    }
  }, [open, group, form])

  const submit = form.handleSubmit(async (values) => {
    try {
      await save.mutateAsync({
        id: group?.id,
        payload: { ...values, priority: Number(values.priority), description: values.description || null },
      })
      toast.success(group ? 'Group updated.' : 'Group created.')
      onOpenChange(false)
    } catch (error) {
      applyServerErrors(error, form.setError, ['name', 'code', 'priority', 'description', 'is_active'])
    }
  })

  const errors = form.formState.errors

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{group ? 'Edit group' : 'New group'}</DialogTitle>
          <DialogDescription>
            Groups cut across departments (e.g. a night crew) and can have their own schedule.
          </DialogDescription>
        </DialogHeader>
        <form id="group-form" onSubmit={submit} className="grid gap-4" noValidate>
          <div className="grid gap-4 sm:grid-cols-[2fr_1fr]">
            <Field label="Name" error={errors.name?.message}>
              <Input {...form.register('name')} />
            </Field>
            <Field label="Code" error={errors.code?.message}>
              <Input className="font-mono" {...form.register('code')} />
            </Field>
          </div>
          <Field
            label="Priority"
            error={errors.priority?.message}
            hint="When an employee is in several groups, the lowest number decides their schedule. Must be unique."
          >
            <Input inputMode="numeric" {...form.register('priority')} />
          </Field>
          <Field label="Description" error={errors.description?.message}>
            <Textarea rows={2} {...form.register('description')} />
          </Field>
          <Controller
            control={form.control}
            name="is_active"
            render={({ field }) => (
              <div className="flex items-center justify-between rounded-lg border p-3">
                <div>
                  <Label htmlFor="group-active">Active</Label>
                  <p className="text-muted-foreground text-xs">Inactive groups cannot receive new members.</p>
                </div>
                <Switch id="group-active" checked={field.value} onCheckedChange={field.onChange} />
              </div>
            )}
          />
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form="group-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            {group ? 'Save changes' : 'Create group'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export function GroupsPage() {
  const can = useCan()
  const manage = can('groups.manage')
  const list = useListParams({ defaultSort: 'priority', filters: ['is_active'] })
  const groups = useGroups(list.params)
  const remove = useDeleteGroup()
  const [creating, setCreating] = useState(false)
  const [editing, setEditing] = useState<Group | null>(null)
  const [managing, setManaging] = useState<Group | null>(null)
  const [deleting, setDeleting] = useState<Group | null>(null)

  const columns: Column<Group>[] = [
    {
      key: 'priority',
      header: 'Priority',
      sortKey: 'priority',
      className: 'w-24',
      cell: (g) => <span className="tabular-nums">{g.priority}</span>,
    },
    {
      key: 'name',
      header: 'Group',
      sortKey: 'name',
      cell: (g) => (
        <div>
          <p className="font-medium">{g.name}</p>
          <p className="text-muted-foreground font-mono text-xs">{g.code}</p>
        </div>
      ),
    },
    {
      key: 'members',
      header: 'Members',
      cell: (g) => (
        <Link to={`/employees?group_id=${g.id}`} className="tabular-nums underline-offset-4 hover:underline">
          {g.members_count ?? 0}
        </Link>
      ),
    },
    {
      key: 'managers',
      header: 'Managers',
      hideBelow: 'md',
      cell: (g) => (
        <span className="text-muted-foreground text-sm">{(g.managers ?? []).map((m) => m.name).join(', ') || '—'}</span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (g) => <StatusBadge tone={g.is_active ? 'success' : 'neutral'}>{g.is_active ? 'active' : 'inactive'}</StatusBadge>,
    },
    {
      key: 'actions',
      header: '',
      className: 'w-12 text-right',
      cell: (g) => (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" className="size-8" aria-label={`Actions for ${g.name}`}>
              <MoreHorizontal />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem asChild>
              <Link to={`/employees?group_id=${g.id}`}>
                <Users /> View members
              </Link>
            </DropdownMenuItem>
            {manage && (
              <>
                <DropdownMenuItem onSelect={() => setEditing(g)}>
                  <Pencil /> Edit
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => setManaging(g)}>
                  <UserCog /> Managers
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(g)}>
                  <Trash2 /> Delete
                </DropdownMenuItem>
              </>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Groups"
        description="Cross-department groups. Members are added from an employee's profile."
        actions={
          manage && (
            <Button onClick={() => setCreating(true)}>
              <Plus /> New group
            </Button>
          )
        }
      />
      <SearchInput value={list.search} onChange={list.setSearch} label="Search groups" />
      <DataTable
        caption="Groups"
        columns={columns}
        list={list}
        query={groups}
        rowKey={(g) => g.id}
        emptyTitle="No groups yet"
        emptyDescription="Use groups for teams that span departments, like shift crews."
        emptyAction={manage ? <Button onClick={() => setCreating(true)}>New group</Button> : undefined}
      />
      <GroupFormDialog open={creating} onOpenChange={setCreating} />
      <GroupFormDialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)} group={editing ?? undefined} />
      <ManagersDialog kind="groups" target={managing} onOpenChange={(open) => !open && setManaging(null)} />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={`Delete ${deleting?.name ?? 'group'}?`}
        description={<p>Groups that have had members cannot be deleted; deactivate them instead.</p>}
        confirmLabel="Delete group"
        destructive
        pending={remove.isPending}
        onConfirm={() => {
          if (!deleting) return
          remove.mutate(deleting.id, {
            onSuccess: () => {
              toast.success('Group deleted.')
              setDeleting(null)
            },
            onError: (e) => toast.error(errorMessage(e)),
          })
        }}
      />
    </>
  )
}
