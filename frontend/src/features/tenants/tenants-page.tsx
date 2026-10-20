import { useState } from 'react'
import { ExternalLink, MoreHorizontal, Pause, Pencil, Play, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { DataTable, type Column } from '@/components/data-table'
import { SearchInput } from '@/components/search-input'
import { PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useListParams } from '@/hooks/use-list-params'
import { formatDate } from '@/lib/format'
import { errorMessage } from '@/lib/forms'
import { tenantWorkspaceUrl } from '@/lib/host'
import { useDeleteTenant, useTenants, useTenantStatusAction, type Tenant, type TenantStatus } from './api'
import { CreateTenantDialog, EditTenantDialog } from './tenant-form-dialogs'

const ALL = 'all'
const statusTone: Record<TenantStatus, 'success' | 'warning' | 'neutral'> = {
  active: 'success',
  suspended: 'warning',
  provisioning: 'neutral',
}

export function TenantsPage() {
  const list = useListParams({ defaultSort: '-created_at', filters: ['status'] })
  const tenants = useTenants(list.params)
  const statusAction = useTenantStatusAction()
  const deleteTenant = useDeleteTenant()

  const [creating, setCreating] = useState(false)
  const [editing, setEditing] = useState<Tenant | null>(null)
  const [suspending, setSuspending] = useState<Tenant | null>(null)
  const [deleting, setDeleting] = useState<Tenant | null>(null)

  const activate = (tenant: Tenant) =>
    statusAction.mutate(
      { id: tenant.id, action: 'activate' },
      { onSuccess: () => toast.success(`${tenant.name} is active again.`), onError: (e) => toast.error(errorMessage(e)) },
    )

  const columns: Column<Tenant>[] = [
    {
      key: 'name',
      header: 'Tenant',
      sortKey: 'name',
      cell: (tenant) => (
        <div className="min-w-0">
          <p className="truncate font-medium">{tenant.name}</p>
          <a
            href={tenantWorkspaceUrl(tenant.subdomain)}
            target="_blank"
            rel="noreferrer"
            className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 font-mono text-xs hover:underline"
          >
            {tenant.subdomain}
            <ExternalLink className="size-3" aria-hidden />
            <span className="sr-only">(opens in a new tab)</span>
          </a>
        </div>
      ),
    },
    { key: 'plan', header: 'Plan', hideBelow: 'sm', cell: (tenant) => tenant.plan.name },
    {
      key: 'status',
      header: 'Status',
      sortKey: 'status',
      cell: (tenant) => <StatusBadge tone={statusTone[tenant.status]}>{tenant.status}</StatusBadge>,
    },
    {
      key: 'created_at',
      header: 'Created',
      sortKey: 'created_at',
      hideBelow: 'md',
      cell: (tenant) => <span className="text-muted-foreground text-sm">{formatDate(tenant.created_at)}</span>,
    },
    {
      key: 'actions',
      header: '',
      className: 'w-12 text-right',
      cell: (tenant) => (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" className="size-8" aria-label={`Actions for ${tenant.name}`}>
              <MoreHorizontal />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onSelect={() => setEditing(tenant)}>
              <Pencil /> Edit
            </DropdownMenuItem>
            {tenant.status === 'active' && (
              <DropdownMenuItem onSelect={() => setSuspending(tenant)}>
                <Pause /> Suspend
              </DropdownMenuItem>
            )}
            {tenant.status === 'suspended' && (
              <DropdownMenuItem onSelect={() => activate(tenant)}>
                <Play /> Activate
              </DropdownMenuItem>
            )}
            <DropdownMenuSeparator />
            {/* Mirrors TenantService::delete(): only suspended tenants can be deleted. */}
            <DropdownMenuItem variant="destructive" disabled={tenant.status !== 'suspended'} onSelect={() => setDeleting(tenant)}>
              <Trash2 /> Delete…
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Tenants"
        description="Organizations using the platform. Each has its own isolated database."
        actions={
          <Button onClick={() => setCreating(true)}>
            <Plus /> New tenant
          </Button>
        }
      />

      <div className="flex flex-col gap-2 sm:flex-row">
        <SearchInput value={list.search} onChange={list.setSearch} label="Search name or subdomain" />
        <Select
          value={list.filter('status') || ALL}
          onValueChange={(value) => list.setFilter('status', value === ALL ? '' : value)}
        >
          <SelectTrigger className="w-full sm:w-44" aria-label="Filter by status">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>Any status</SelectItem>
            <SelectItem value="active">Active</SelectItem>
            <SelectItem value="suspended">Suspended</SelectItem>
            <SelectItem value="provisioning">Provisioning</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <DataTable
        caption="Tenants"
        columns={columns}
        list={list}
        query={tenants}
        rowKey={(tenant) => tenant.id}
        emptyTitle="No tenants yet"
        emptyDescription="Create the first organization to get started."
        emptyAction={<Button onClick={() => setCreating(true)}>New tenant</Button>}
      />

      <CreateTenantDialog open={creating} onOpenChange={setCreating} />
      <EditTenantDialog tenant={editing} onOpenChange={(open) => !open && setEditing(null)} />

      <ConfirmDialog
        open={suspending !== null}
        onOpenChange={(open) => !open && setSuspending(null)}
        title={`Suspend ${suspending?.name ?? 'tenant'}?`}
        description={<p>Nobody in this workspace can sign in or use the API until it is activated again. No data is removed.</p>}
        confirmLabel="Suspend"
        pending={statusAction.isPending}
        onConfirm={() => {
          if (!suspending) return
          statusAction.mutate(
            { id: suspending.id, action: 'suspend' },
            {
              onSuccess: () => {
                toast.success(`${suspending.name} suspended.`)
                setSuspending(null)
              },
              onError: (e) => toast.error(errorMessage(e)),
            },
          )
        }}
      />

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={`Permanently delete ${deleting?.name ?? 'tenant'}?`}
        description={
          <>
            <p>The workspace and its entire database will be removed. A final backup is written to the server before deletion.</p>
            <p className="text-destructive font-medium">This cannot be undone from the app.</p>
          </>
        }
        confirmLabel="Delete tenant"
        destructive
        confirmText={deleting?.subdomain}
        pending={deleteTenant.isPending}
        onConfirm={(typed) => {
          if (!deleting) return
          deleteTenant.mutate(
            { id: deleting.id, confirm: typed },
            {
              onSuccess: () => {
                toast.success(`${deleting.name} deleted.`)
                setDeleting(null)
              },
              onError: (e) => toast.error(errorMessage(e)),
            },
          )
        }}
      />
    </>
  )
}
