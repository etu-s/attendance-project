import { useState } from 'react'
import { MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { DataTable, type Column } from '@/components/data-table'
import { SearchInput } from '@/components/search-input'
import { PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { useListParams } from '@/hooks/use-list-params'
import { errorMessage } from '@/lib/forms'
import { useDeletePlan, usePlans, type Plan } from './api'
import { PlanFormDialog } from './plan-form-dialog'

export function PlansPage() {
  const list = useListParams({ defaultSort: 'name', filters: ['is_active'] })
  const plans = usePlans(list.params)
  const deletePlan = useDeletePlan()
  const [editing, setEditing] = useState<Plan | null>(null)
  const [creating, setCreating] = useState(false)
  const [deleting, setDeleting] = useState<Plan | null>(null)

  const columns: Column<Plan>[] = [
    {
      key: 'name',
      header: 'Plan',
      sortKey: 'name',
      cell: (plan) => (
        <div>
          <p className="font-medium">{plan.name}</p>
          <p className="text-muted-foreground font-mono text-xs">{plan.slug}</p>
        </div>
      ),
    },
    {
      key: 'max_employees',
      header: 'Employees',
      sortKey: 'max_employees',
      cell: (plan) => <span className="tabular-nums">{plan.max_employees ?? 'Unlimited'}</span>,
    },
    {
      key: 'features',
      header: 'Features',
      hideBelow: 'md',
      cell: (plan) => (
        <div className="flex flex-wrap gap-1">
          {plan.features.length === 0 ? (
            <span className="text-muted-foreground">—</span>
          ) : (
            plan.features.map((feature) => (
              <Badge key={feature} variant="secondary" className="font-normal">
                {feature}
              </Badge>
            ))
          )}
        </div>
      ),
    },
    {
      key: 'tenants',
      header: 'Tenants',
      hideBelow: 'sm',
      cell: (plan) => <span className="tabular-nums">{plan.tenants_count ?? 0}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (plan) => (
        <StatusBadge tone={plan.is_active ? 'success' : 'neutral'}>{plan.is_active ? 'active' : 'inactive'}</StatusBadge>
      ),
    },
    {
      key: 'actions',
      header: '',
      className: 'w-12 text-right',
      cell: (plan) => (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" className="size-8" aria-label={`Actions for ${plan.name}`}>
              <MoreHorizontal />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onSelect={() => setEditing(plan)}>
              <Pencil /> Edit
            </DropdownMenuItem>
            <DropdownMenuItem variant="destructive" disabled={(plan.tenants_count ?? 0) > 0} onSelect={() => setDeleting(plan)}>
              <Trash2 /> Delete
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Plans"
        description="Subscription plans and their limits."
        actions={
          <Button onClick={() => setCreating(true)}>
            <Plus /> New plan
          </Button>
        }
      />
      <SearchInput value={list.search} onChange={list.setSearch} label="Search plans" />
      <DataTable
        caption="Plans"
        columns={columns}
        list={list}
        query={plans}
        rowKey={(plan) => plan.id}
        emptyTitle="No plans yet"
        emptyDescription="Create a plan before adding tenants."
        emptyAction={<Button onClick={() => setCreating(true)}>New plan</Button>}
      />

      <PlanFormDialog open={creating} onOpenChange={setCreating} />
      <PlanFormDialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)} plan={editing ?? undefined} />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={`Delete ${deleting?.name ?? 'plan'}?`}
        description={<p>This cannot be undone. Plans assigned to tenants cannot be deleted; deactivate them instead.</p>}
        confirmLabel="Delete plan"
        destructive
        pending={deletePlan.isPending}
        onConfirm={() => {
          if (!deleting) return
          deletePlan.mutate(deleting.id, {
            onSuccess: () => {
              toast.success('Plan deleted.')
              setDeleting(null)
            },
            onError: (error) => toast.error(errorMessage(error)),
          })
        }}
      />
    </>
  )
}
