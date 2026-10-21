import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2, MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-react'
import { Controller, useForm } from 'react-hook-form'
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
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { useCan, useSession } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { applyServerErrors, errorMessage } from '@/lib/forms'
import { useDeleteLocation, useLocations, useSaveLocation, type Location } from './api'

const timezones: string[] = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : []

// Mirrors LocationRequest.php
const schema = z.object({
  name: z.string().trim().min(1, 'Enter a name.').max(120),
  code: z
    .string()
    .trim()
    .min(1, 'Enter a code.')
    .max(30)
    .regex(/^[A-Za-z0-9._-]+$/, 'Letters, numbers, dots, dashes or underscores only.'),
  address: z.string().max(500),
  timezone: z
    .string()
    .refine((v) => v === '' || timezones.length === 0 || timezones.includes(v), 'Choose a timezone from the list.'),
  is_active: z.boolean(),
})

type Values = z.infer<typeof schema>

function LocationFormDialog({
  open,
  onOpenChange,
  location,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  location?: Location
}) {
  const save = useSaveLocation()
  const { workspace } = useSession()
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { name: '', code: '', address: '', timezone: '', is_active: true },
  })

  useEffect(() => {
    if (open) {
      form.reset({
        name: location?.name ?? '',
        code: location?.code ?? '',
        address: location?.address ?? '',
        timezone: location?.timezone ?? '',
        is_active: location?.is_active ?? true,
      })
    }
  }, [open, location, form])

  const submit = form.handleSubmit(async (values) => {
    try {
      await save.mutateAsync({
        id: location?.id,
        payload: { ...values, address: values.address || null, timezone: values.timezone || null },
      })
      toast.success(location ? 'Location updated.' : 'Location created.')
      onOpenChange(false)
    } catch (error) {
      applyServerErrors(error, form.setError, ['name', 'code', 'address', 'timezone', 'is_active'])
    }
  })

  const errors = form.formState.errors

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{location ? 'Edit location' : 'New location'}</DialogTitle>
          <DialogDescription>A site or branch employees are assigned to.</DialogDescription>
        </DialogHeader>
        <form id="location-form" onSubmit={submit} className="grid gap-4" noValidate>
          <div className="grid gap-4 sm:grid-cols-[2fr_1fr]">
            <Field label="Name" error={errors.name?.message}>
              <Input {...form.register('name')} />
            </Field>
            <Field label="Code" error={errors.code?.message}>
              <Input className="font-mono" {...form.register('code')} />
            </Field>
          </div>
          <Field
            label="Timezone"
            error={errors.timezone?.message}
            hint={`Leave empty to use the workspace timezone (${workspace.timezone}).`}
          >
            <Input list="location-timezones" placeholder={workspace.timezone} {...form.register('timezone')} />
          </Field>
          <datalist id="location-timezones">
            {timezones.map((zone) => (
              <option key={zone} value={zone} />
            ))}
          </datalist>
          <Field label="Address" error={errors.address?.message}>
            <Textarea rows={2} {...form.register('address')} />
          </Field>
          <Controller
            control={form.control}
            name="is_active"
            render={({ field }) => (
              <div className="flex items-center justify-between rounded-lg border p-3">
                <div>
                  <Label htmlFor="location-active">Active</Label>
                  <p className="text-muted-foreground text-xs">Inactive locations cannot be assigned.</p>
                </div>
                <Switch id="location-active" checked={field.value} onCheckedChange={field.onChange} />
              </div>
            )}
          />
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form="location-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            {location ? 'Save changes' : 'Create location'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export function LocationsPage() {
  const can = useCan()
  const { workspace } = useSession()
  const manage = can('locations.manage')
  const list = useListParams({ defaultSort: 'name', filters: ['is_active'] })
  const locations = useLocations(list.params)
  const remove = useDeleteLocation()
  const [creating, setCreating] = useState(false)
  const [editing, setEditing] = useState<Location | null>(null)
  const [deleting, setDeleting] = useState<Location | null>(null)

  const columns: Column<Location>[] = [
    {
      key: 'name',
      header: 'Location',
      sortKey: 'name',
      cell: (l) => (
        <div>
          <p className="font-medium">{l.name}</p>
          <p className="text-muted-foreground font-mono text-xs">{l.code}</p>
        </div>
      ),
    },
    {
      key: 'timezone',
      header: 'Timezone',
      cell: (l) => l.timezone ?? <span className="text-muted-foreground">Workspace ({workspace.timezone})</span>,
    },
    {
      key: 'address',
      header: 'Address',
      hideBelow: 'md',
      cell: (l) => <span className="text-muted-foreground text-sm">{l.address ?? '—'}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (l) => <StatusBadge tone={l.is_active ? 'success' : 'neutral'}>{l.is_active ? 'active' : 'inactive'}</StatusBadge>,
    },
    {
      key: 'actions',
      header: '',
      className: 'w-12 text-right',
      cell: (l) =>
        manage ? (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="size-8" aria-label={`Actions for ${l.name}`}>
                <MoreHorizontal />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onSelect={() => setEditing(l)}>
                <Pencil /> Edit
              </DropdownMenuItem>
              <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(l)}>
                <Trash2 /> Delete
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader
        title="Locations"
        description="Sites and branches. A location's timezone overrides the workspace timezone for its employees."
        actions={
          manage && (
            <Button onClick={() => setCreating(true)}>
              <Plus /> New location
            </Button>
          )
        }
      />
      <SearchInput value={list.search} onChange={list.setSearch} label="Search locations" />
      <DataTable
        caption="Locations"
        columns={columns}
        list={list}
        query={locations}
        rowKey={(l) => l.id}
        emptyTitle="No locations yet"
        emptyDescription="Add sites if employees work in different places or timezones."
        emptyAction={manage ? <Button onClick={() => setCreating(true)}>New location</Button> : undefined}
      />
      <LocationFormDialog open={creating} onOpenChange={setCreating} />
      <LocationFormDialog
        open={editing !== null}
        onOpenChange={(open) => !open && setEditing(null)}
        location={editing ?? undefined}
      />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={`Delete ${deleting?.name ?? 'location'}?`}
        description={<p>Locations that appear in employee history cannot be deleted; deactivate them instead.</p>}
        confirmLabel="Delete location"
        destructive
        pending={remove.isPending}
        onConfirm={() => {
          if (!deleting) return
          remove.mutate(deleting.id, {
            onSuccess: () => {
              toast.success('Location deleted.')
              setDeleting(null)
            },
            onError: (e) => toast.error(errorMessage(e)),
          })
        }}
      />
    </>
  )
}
