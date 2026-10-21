import { useState, type ReactNode } from 'react'
import { MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-react'
import { Link, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { DataTable, type Column } from '@/components/data-table'
import { PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useCan, useDisplayPreferences, useSession } from '@/features/tenant-auth/hooks'
import { cycleLabel } from '@/features/timetables/time'
import { useListParams } from '@/hooks/use-list-params'
import { formatLocalDate, todayIn } from '@/lib/format'
import { errorMessage } from '@/lib/forms'
import {
  targetLabels,
  useDeleteAssignment,
  useScheduleAssignments,
  type ScheduleAssignment,
  type ScheduleTargetType,
} from './api'
import { AssignDialog, EditAssignmentDialog } from './assignment-dialogs'
import { periodState } from './day-status'
import { TemporarySchedulesView } from './temporary-schedules-view'

type Tab = ScheduleTargetType | 'temporary'

const tabs: Tab[] = ['organization', 'department', 'group', 'employee', 'temporary']

function tabLabel(tab: Tab): string {
  if (tab === 'organization') return 'Organization'
  if (tab === 'temporary') return 'Temporary'
  return `${targetLabels[tab]}s`
}

export function SchedulesPage() {
  const can = useCan()
  const manage = can('schedules.manage')
  const [searchParams, setSearchParams] = useSearchParams()
  const requested = searchParams.get('tab') as Tab | null
  const tab: Tab = requested !== null && tabs.includes(requested) ? requested : 'organization'
  const [creating, setCreating] = useState(false)

  // Each tab has its own filters and sort keys, so switching starts from a clean URL.
  const tabList = (
    <Tabs value={tab} onValueChange={(v) => setSearchParams(v === 'organization' ? {} : { tab: v }, { replace: true })}>
      <TabsList className="h-auto flex-wrap">
        {tabs.map((t) => (
          <TabsTrigger key={t} value={t}>
            {tabLabel(t)}
          </TabsTrigger>
        ))}
      </TabsList>
    </Tabs>
  )

  return (
    <>
      <PageHeader
        title="Schedules"
        description="Which shift applies to whom. Temporary > employee > group (by priority) > department (or its parent) > organization default."
        actions={
          manage && (
            <Button onClick={() => setCreating(true)}>
              <Plus /> {tab === 'temporary' ? 'Temporary change' : 'Assign shift'}
            </Button>
          )
        }
      />
      {tab === 'temporary' ? (
        <TemporarySchedulesView tabs={tabList} manage={manage} creating={creating} setCreating={setCreating} />
      ) : (
        <AssignmentsView type={tab} tabs={tabList} manage={manage} creating={creating} setCreating={setCreating} />
      )}
    </>
  )
}

function AssignmentsView({
  type,
  tabs,
  manage,
  creating,
  setCreating,
}: {
  type: ScheduleTargetType
  tabs: ReactNode
  manage: boolean
  creating: boolean
  setCreating: (open: boolean) => void
}) {
  const prefs = useDisplayPreferences()
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  const list = useListParams({ defaultSort: '-effective_from', filters: ['active_on'] })
  const assignments = useScheduleAssignments({ ...list.params, target_type: type })
  const remove = useDeleteAssignment()
  const [editing, setEditing] = useState<ScheduleAssignment | null>(null)
  const [deleting, setDeleting] = useState<ScheduleAssignment | null>(null)

  const columns: Column<ScheduleAssignment>[] = [
    ...(type === 'organization'
      ? []
      : [
          {
            key: 'target',
            header: targetLabels[type],
            cell: (a: ScheduleAssignment) =>
              a.target.type === 'employee' && a.target.id ? (
                <Link to={`/employees/${String(a.target.id)}`} className="font-medium underline-offset-4 hover:underline">
                  {a.target.name ?? 'Deleted'}
                </Link>
              ) : (
                <span className="font-medium">{a.target.name ?? 'Deleted'}</span>
              ),
          },
        ]),
    {
      key: 'shift',
      header: 'Shift',
      cell: (a) => (
        <div>
          <Link to={`/shifts/${String(a.shift.id)}`} className="font-medium underline-offset-4 hover:underline">
            {a.shift.name}
          </Link>
          <p className="text-muted-foreground text-xs">
            every {cycleLabel(a.shift)} · cycle from {formatLocalDate(a.anchor_date, prefs)}
          </p>
        </div>
      ),
    },
    {
      key: 'from',
      header: 'From',
      sortKey: 'effective_from',
      cell: (a) => <span className="tabular-nums">{formatLocalDate(a.effective_from, prefs)}</span>,
    },
    {
      key: 'to',
      header: 'Until',
      cell: (a) => <span className="tabular-nums">{a.effective_to ? formatLocalDate(a.effective_to, prefs) : 'Open'}</span>,
    },
    {
      key: 'state',
      header: 'Status',
      hideBelow: 'sm',
      cell: (a) => {
        const s = periodState(a.effective_from, a.effective_to, today)
        return <StatusBadge tone={s.tone}>{s.label}</StatusBadge>
      },
    },
    {
      key: 'actions',
      header: '',
      className: 'w-12 text-right',
      cell: (a) =>
        manage ? (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                className="size-8"
                aria-label={`Actions for ${a.shift.name} from ${a.effective_from}`}
              >
                <MoreHorizontal />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onSelect={() => setEditing(a)}>
                <Pencil /> Edit
              </DropdownMenuItem>
              <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(a)}>
                <Trash2 /> Delete
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        ) : null,
    },
  ]

  return (
    <>
      <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        {tabs}
        <div className="grid gap-1.5">
          <Label htmlFor="active-on">Active on</Label>
          <Input
            id="active-on"
            type="date"
            className="w-44"
            value={list.filter('active_on')}
            onChange={(e) => list.setFilter('active_on', e.target.value)}
          />
        </div>
      </div>
      <DataTable
        caption={`${targetLabels[type]} schedules`}
        columns={columns}
        list={list}
        query={assignments}
        rowKey={(a) => a.id}
        emptyTitle={type === 'organization' ? 'No organization default' : `No ${type} schedules`}
        emptyDescription={
          type === 'organization'
            ? 'Set a default shift that applies to everyone without a more specific schedule.'
            : `Assign a shift to a ${type} to override the levels below it.`
        }
        emptyAction={manage ? <Button onClick={() => setCreating(true)}>Assign shift</Button> : undefined}
      />
      <AssignDialog type={type} open={creating} onClose={() => setCreating(false)} />
      <EditAssignmentDialog assignment={editing} onClose={() => setEditing(null)} />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title="Delete this schedule period?"
        description={<p>For these dates the next level down (or no schedule) applies instead.</p>}
        confirmLabel="Delete"
        destructive
        pending={remove.isPending}
        onConfirm={() => {
          if (!deleting) return
          remove.mutate(deleting.id, {
            onSuccess: () => {
              toast.success('Schedule period deleted.')
              setDeleting(null)
            },
            onError: (e) => toast.error(errorMessage(e)),
          })
        }}
      />
    </>
  )
}
