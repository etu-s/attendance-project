import { useState, type ReactNode } from 'react'
import { MoreHorizontal, Pencil, Trash2 } from 'lucide-react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { DataTable, type Column } from '@/components/data-table'
import { SearchInput } from '@/components/search-input'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useDisplayPreferences, useSession } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { formatLocalDate, todayIn } from '@/lib/format'
import { errorMessage } from '@/lib/forms'
import { useAllTemporarySchedules, useDeleteTemporary, type TemporarySchedule } from './api'
import { periodState } from './day-status'
import { TemporaryDialog } from './temporary-dialog'

/** "Temporary" tab of the Schedules page: every employee's one-off changes. */
export function TemporarySchedulesView({
  tabs,
  manage,
  creating,
  setCreating,
}: {
  tabs: ReactNode
  manage: boolean
  creating: boolean
  setCreating: (open: boolean) => void
}) {
  const prefs = useDisplayPreferences()
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  const list = useListParams({ defaultSort: '-date_from', filters: ['active_on', 'kind'] })
  const temporaries = useAllTemporarySchedules(list.params)
  const remove = useDeleteTemporary()
  const [editing, setEditing] = useState<TemporarySchedule | null>(null)
  const [deleting, setDeleting] = useState<TemporarySchedule | null>(null)

  const columns: Column<TemporarySchedule>[] = [
    {
      key: 'employee',
      header: 'Employee',
      cell: (t) => (
        <div>
          <Link to={`/employees/${String(t.employee_id)}`} className="font-medium underline-offset-4 hover:underline">
            {t.employee?.full_name ?? 'Deleted'}
          </Link>
          {t.employee && <p className="text-muted-foreground text-xs">{t.employee.employee_code}</p>}
        </div>
      ),
    },
    {
      key: 'change',
      header: 'Change',
      cell: (t) =>
        t.day_off ? <StatusBadge tone="holiday">day off</StatusBadge> : <span className="font-medium">{t.shift?.name}</span>,
    },
    {
      key: 'from',
      header: 'From',
      sortKey: 'date_from',
      cell: (t) => <span className="tabular-nums">{formatLocalDate(t.date_from, prefs)}</span>,
    },
    {
      key: 'to',
      header: 'Until',
      cell: (t) => <span className="tabular-nums">{formatLocalDate(t.date_to, prefs)}</span>,
    },
    {
      key: 'reason',
      header: 'Reason',
      hideBelow: 'md',
      cell: (t) => <span className="text-muted-foreground">{t.reason ?? '—'}</span>,
    },
    {
      key: 'state',
      header: 'Status',
      hideBelow: 'sm',
      cell: (t) => {
        const s = periodState(t.date_from, t.date_to, today)
        return <StatusBadge tone={s.tone}>{s.label}</StatusBadge>
      },
    },
    {
      key: 'actions',
      header: '',
      className: 'w-12 text-right',
      cell: (t) =>
        manage ? (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                className="size-8"
                aria-label={`Actions for ${t.employee?.full_name ?? 'employee'} from ${t.date_from}`}
              >
                <MoreHorizontal />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onSelect={() => setEditing(t)}>
                <Pencil /> Edit
              </DropdownMenuItem>
              <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(t)}>
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
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
          <SearchInput value={list.search} onChange={list.setSearch} label="Search employees" />
          <div className="grid gap-1.5">
            <Label htmlFor="temporary-kind">Type</Label>
            <Select value={list.filter('kind') || 'all'} onValueChange={(v) => list.setFilter('kind', v === 'all' ? '' : v)}>
              <SelectTrigger id="temporary-kind" className="w-36">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All</SelectItem>
                <SelectItem value="shift">Shift change</SelectItem>
                <SelectItem value="day_off">Day off</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="temporary-active-on">Active on</Label>
            <Input
              id="temporary-active-on"
              type="date"
              className="w-44"
              value={list.filter('active_on')}
              onChange={(e) => list.setFilter('active_on', e.target.value)}
            />
          </div>
        </div>
      </div>
      <DataTable
        caption="Temporary schedules"
        columns={columns}
        list={list}
        query={temporaries}
        rowKey={(t) => t.id}
        emptyTitle="No temporary changes"
        emptyDescription="Give one employee a different shift, or days off, for a few dates. It beats every other schedule and holidays."
        emptyAction={manage ? <Button onClick={() => setCreating(true)}>Temporary change</Button> : undefined}
      />
      <TemporaryDialog
        employeeId={null}
        target={creating ? 'new' : editing}
        onClose={() => {
          setCreating(false)
          setEditing(null)
        }}
      />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title="Remove this temporary change?"
        description={<p>The standing schedule applies again for those dates.</p>}
        confirmLabel="Remove"
        destructive
        pending={remove.isPending}
        onConfirm={() => {
          if (!deleting) return
          remove.mutate(deleting.id, {
            onSuccess: () => {
              toast.success('Temporary change removed.')
              setDeleting(null)
            },
            onError: (e) => toast.error(errorMessage(e)),
          })
        }}
      />
    </>
  )
}
