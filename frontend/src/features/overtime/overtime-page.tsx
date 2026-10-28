import { useState } from 'react'
import { Check, CheckCheck, MoreHorizontal, Pencil, Plus, Trash2, X } from 'lucide-react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { DataTable, type Column } from '@/components/data-table'
import { SearchInput } from '@/components/search-input'
import { PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useCan, useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { formatLocalDate } from '@/lib/format'
import { errorMessage } from '@/lib/forms'
import { useApproveOvertimeBatch, useDeleteOvertimeEntry, useOvertime, type OvertimeRecord, type OvertimeStatus } from './api'
import { formatDuration, overtimeBreakdown, overtimeStatusTone } from './format'
import { DecisionDialog, ManualEntryDialog, type OvertimeDecision } from './overtime-dialogs'

const tabs: OvertimeStatus[] = ['pending', 'approved', 'rejected']

/** Approvers' inbox: overtime of the people the viewer can see. */
export function OvertimePage() {
  const can = useCan()
  const prefs = useDisplayPreferences()
  const manage = can('overtime.manage')
  const list = useListParams({ defaultSort: '-work_date', filters: ['status', 'from', 'to', 'changed'], defaultPerPage: 25 })
  const status = (list.filter('status') || 'pending') as OvertimeStatus
  const overtime = useOvertime({ ...list.params, status })
  const batch = useApproveOvertimeBatch()
  const remove = useDeleteOvertimeEntry()
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [decision, setDecision] = useState<OvertimeDecision | null>(null)
  const [entry, setEntry] = useState<OvertimeRecord | 'new' | null>(null)
  const [deleting, setDeleting] = useState<OvertimeRecord | null>(null)

  const rows = overtime.data?.data ?? []
  const decidable = rows.filter((r) => r.can_decide && r.calculated_minutes > 0)
  const toggle = (id: number) =>
    setSelected((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })

  const columns: Column<OvertimeRecord>[] = [
    ...(status === 'pending'
      ? [
          {
            key: 'select',
            header: '',
            className: 'w-10',
            cell: (r: OvertimeRecord) =>
              r.can_decide && r.calculated_minutes > 0 ? (
                <Checkbox
                  checked={selected.has(r.id)}
                  onCheckedChange={() => toggle(r.id)}
                  aria-label={`Select overtime of ${r.employee.full_name} on ${r.work_date}`}
                />
              ) : null,
          },
        ]
      : []),
    {
      key: 'employee',
      header: 'Employee',
      cell: (r) => (
        <div>
          <Link to={`/employees/${String(r.employee.id)}`} className="font-medium underline-offset-4 hover:underline">
            {r.employee.full_name}
          </Link>
          <p className="text-muted-foreground text-xs">{r.employee.employee_code}</p>
        </div>
      ),
    },
    {
      key: 'date',
      header: 'Date',
      sortKey: 'work_date',
      cell: (r) => <span className="tabular-nums">{formatLocalDate(r.work_date, prefs)}</span>,
    },
    {
      key: 'minutes',
      header: 'Overtime',
      sortKey: 'calculated_minutes',
      cell: (r) => (
        <div>
          <p className="font-medium tabular-nums">
            {r.status === 'approved' && r.approved_minutes !== null && r.approved_minutes !== r.calculated_minutes
              ? `${formatDuration(r.approved_minutes)} of ${formatDuration(r.calculated_minutes)}`
              : formatDuration(r.calculated_minutes)}
          </p>
          <p className="text-muted-foreground text-xs">{overtimeBreakdown(r)}</p>
        </div>
      ),
    },
    {
      key: 'source',
      header: 'Source',
      hideBelow: 'md',
      cell: (r) => (
        <div className="text-xs">
          <p>{r.source === 'manual' ? 'Manual' : 'From attendance'}</p>
          {r.reason && <p className="text-muted-foreground">{r.reason}</p>}
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <div className="space-y-1">
          <StatusBadge tone={overtimeStatusTone[r.status]}>{r.status}</StatusBadge>
          {r.changed_after_decision && <p className="text-status-late text-xs">Changed after a decision</p>}
          {r.decision_note && <p className="text-muted-foreground text-xs">{r.decision_note}</p>}
        </div>
      ),
    },
    {
      key: 'actions',
      header: '',
      className: 'w-28 text-right',
      cell: (r) => (
        <div className="flex justify-end gap-1">
          {r.can_decide && r.calculated_minutes > 0 && (
            <Button
              variant="ghost"
              size="icon"
              className="size-8"
              aria-label={`Approve overtime of ${r.employee.full_name}`}
              onClick={() => setDecision({ record: r, decision: 'approve' })}
            >
              <Check />
            </Button>
          )}
          {r.can_decide && (
            <Button
              variant="ghost"
              size="icon"
              className="size-8"
              aria-label={`Reject overtime of ${r.employee.full_name}`}
              onClick={() => setDecision({ record: r, decision: 'reject' })}
            >
              <X />
            </Button>
          )}
          {manage && r.source === 'manual' && r.status === 'pending' && (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-8" aria-label={`More for ${r.employee.full_name}`}>
                  <MoreHorizontal />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={() => setEntry(r)}>
                  <Pencil /> Edit
                </DropdownMenuItem>
                <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(r)}>
                  <Trash2 /> Delete
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          )}
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Overtime"
        description="Calculated from attendance; time after the shift counts once the day's scheduled time is made up."
        actions={
          manage && (
            <Button onClick={() => setEntry('new')}>
              <Plus /> Record overtime
            </Button>
          )
        }
      />
      <div className="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end lg:justify-between">
        <Tabs
          value={status}
          onValueChange={(v) => {
            setSelected(new Set())
            list.setFilter('status', v === 'pending' ? '' : v)
          }}
        >
          <TabsList>
            {tabs.map((t) => (
              <TabsTrigger key={t} value={t} className="capitalize">
                {t}
              </TabsTrigger>
            ))}
          </TabsList>
        </Tabs>
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
          <SearchInput value={list.search} onChange={list.setSearch} label="Search employees" />
          <div className="grid gap-1.5">
            <Label htmlFor="ot-from">From</Label>
            <Input
              id="ot-from"
              type="date"
              className="w-40"
              value={list.filter('from')}
              onChange={(e) => list.setFilter('from', e.target.value)}
            />
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="ot-to">To</Label>
            <Input
              id="ot-to"
              type="date"
              className="w-40"
              value={list.filter('to')}
              onChange={(e) => list.setFilter('to', e.target.value)}
            />
          </div>
        </div>
      </div>

      {status === 'pending' && decidable.length > 0 && (
        <div className="flex flex-wrap items-center gap-3 rounded-lg border px-3 py-2">
          <label className="flex items-center gap-2 text-sm">
            <Checkbox
              checked={decidable.every((r) => selected.has(r.id))}
              onCheckedChange={(c) => setSelected(c === true ? new Set(decidable.map((r) => r.id)) : new Set())}
            />
            Select all on this page
          </label>
          <Button
            size="sm"
            className="ml-auto"
            disabled={selected.size === 0 || batch.isPending}
            onClick={() =>
              batch.mutate([...selected], {
                onSuccess: (r) => {
                  toast.success(
                    `Approved ${String(r.approved)}${r.skipped > 0 ? `; ${String(r.skipped)} skipped (yours or no longer pending)` : ''}.`,
                  )
                  setSelected(new Set())
                },
                onError: (e) => toast.error(errorMessage(e)),
              })
            }
          >
            <CheckCheck /> Approve selected ({selected.size})
          </Button>
        </div>
      )}

      <DataTable
        caption="Overtime"
        columns={columns}
        list={list}
        query={overtime}
        rowKey={(r) => r.id}
        emptyTitle={status === 'pending' ? 'Nothing waiting' : `No ${status} overtime`}
        emptyDescription={status === 'pending' ? 'Overtime appears here as attendance is calculated.' : undefined}
      />
      <DecisionDialog target={decision} onClose={() => setDecision(null)} />
      <ManualEntryDialog
        open={entry !== null}
        employeeId={null}
        editing={entry === 'new' ? null : entry}
        onClose={() => setEntry(null)}
      />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title="Delete this manual overtime?"
        description={<p>Only pending manual entries can be deleted.</p>}
        confirmLabel="Delete"
        destructive
        pending={remove.isPending}
        onConfirm={() => {
          if (!deleting) return
          remove.mutate(deleting.id, {
            onSuccess: () => {
              toast.success('Entry deleted.')
              setDeleting(null)
            },
            onError: (e) => toast.error(errorMessage(e)),
          })
        }}
      />
    </>
  )
}
