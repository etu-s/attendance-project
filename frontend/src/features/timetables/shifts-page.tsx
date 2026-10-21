import { useState } from 'react'
import { Copy, MoreHorizontal, Plus, Trash2 } from 'lucide-react'
import { Link, useNavigate } from 'react-router'
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
import { useCan } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { errorMessage } from '@/lib/forms'
import { useDeleteShift, useDuplicateShift, useShifts, type Shift } from './api'
import { ShiftFormDialog } from './shift-form-dialog'
import { cycleLabel } from './time'

/** One cell per cycle day, coloured by its first timetable; rest days stay empty. */
function CycleStrip({ shift }: { shift: Shift }) {
  const byDay = new Map((shift.days ?? []).map((d) => [d.day_index, d.timetables[0]]))
  const cells = Math.min(shift.cycle_days, 28)

  return (
    <div
      className="flex gap-0.5"
      aria-label={`${String(shift.working_days ?? 0)} working days in a ${String(shift.cycle_days)}-day cycle`}
      role="img"
    >
      {Array.from({ length: cells }, (_, i) => {
        const timetable = byDay.get(i)
        return (
          <span
            key={i}
            className="h-3 w-2 rounded-[2px] border"
            style={timetable ? { backgroundColor: timetable.color ?? 'var(--primary)', borderColor: 'transparent' } : undefined}
          />
        )
      })}
      {shift.cycle_days > cells && <span className="text-muted-foreground text-xs">…</span>}
    </div>
  )
}

export function ShiftsPage() {
  const can = useCan()
  const manage = can('shifts.manage')
  const navigate = useNavigate()
  const list = useListParams({ defaultSort: 'name', filters: ['is_active'] })
  const shifts = useShifts(list.params)
  const duplicate = useDuplicateShift()
  const remove = useDeleteShift()
  const [creating, setCreating] = useState(false)
  const [deleting, setDeleting] = useState<Shift | null>(null)

  const columns: Column<Shift>[] = [
    {
      key: 'name',
      header: 'Shift',
      sortKey: 'name',
      cell: (s) => (
        <div className="min-w-0">
          <Link
            to={`/shifts/${String(s.id)}`}
            className="block truncate font-medium underline-offset-4 hover:underline"
            onClick={(e) => e.stopPropagation()}
          >
            {s.name}
          </Link>
          <p className="text-muted-foreground font-mono text-xs">{s.code}</p>
        </div>
      ),
    },
    { key: 'cycle', header: 'Cycle', cell: (s) => <span className="text-sm">{cycleLabel(s)}</span> },
    { key: 'pattern', header: 'Pattern', hideBelow: 'sm', cell: (s) => <CycleStrip shift={s} /> },
    {
      key: 'working',
      header: 'Working days',
      hideBelow: 'md',
      cell: (s) => <span className="tabular-nums">{s.working_days ?? 0}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (s) => <StatusBadge tone={s.is_active ? 'success' : 'neutral'}>{s.is_active ? 'active' : 'inactive'}</StatusBadge>,
    },
    {
      key: 'actions',
      header: '',
      className: 'w-12 text-right',
      cell: (s) =>
        manage ? (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                className="size-8"
                aria-label={`Actions for ${s.name}`}
                onClick={(e) => e.stopPropagation()}
              >
                <MoreHorizontal />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem
                onSelect={() =>
                  duplicate.mutate(s.id, {
                    onSuccess: (copy) => {
                      toast.success(`Created ${copy.name}.`)
                      void navigate(`/shifts/${String(copy.id)}`)
                    },
                    onError: (e) => toast.error(errorMessage(e)),
                  })
                }
              >
                <Copy /> Duplicate
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(s)}>
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
        title="Shifts"
        description="Repeating patterns of timetables: a working week or a day rotation."
        actions={
          manage && (
            <Button onClick={() => setCreating(true)}>
              <Plus /> New shift
            </Button>
          )
        }
      />
      <SearchInput value={list.search} onChange={list.setSearch} label="Search shifts" />
      <DataTable
        caption="Shifts"
        columns={columns}
        list={list}
        query={shifts}
        rowKey={(s) => s.id}
        onRowClick={(s) => void navigate(`/shifts/${String(s.id)}`)}
        emptyTitle="No shifts yet"
        emptyDescription="Create timetables first, then combine them into a weekly or rotating shift."
        emptyAction={manage ? <Button onClick={() => setCreating(true)}>New shift</Button> : undefined}
      />
      <ShiftFormDialog open={creating} onOpenChange={setCreating} onSaved={(s) => void navigate(`/shifts/${String(s.id)}`)} />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={`Delete ${deleting?.name ?? 'shift'}?`}
        description={<p>Shifts used by a schedule cannot be deleted; deactivate them instead.</p>}
        confirmLabel="Delete shift"
        destructive
        pending={remove.isPending}
        onConfirm={() => {
          if (!deleting) return
          remove.mutate(deleting.id, {
            onSuccess: () => {
              toast.success('Shift deleted.')
              setDeleting(null)
            },
            onError: (e) => toast.error(errorMessage(e)),
          })
        }}
      />
    </>
  )
}
