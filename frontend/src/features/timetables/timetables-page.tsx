import { useState } from 'react'
import { Copy, MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { DataTable, type Column } from '@/components/data-table'
import { SearchInput } from '@/components/search-input'
import { PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useCan } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { errorMessage } from '@/lib/forms'
import { useDeleteTimetable, useDuplicateTimetable, useTimetables, type Timetable } from './api'
import { formatDuration, timeRange } from './time'
import { TimetableFormDialog } from './timetable-form-dialog'

const ALL = 'all'

export function ColorDot({ color }: { color: string | null }) {
  return (
    <span
      className="inline-block size-2.5 shrink-0 rounded-full"
      style={{ backgroundColor: color ?? 'var(--muted-foreground)' }}
      aria-hidden
    />
  )
}

export function TimetablesPage() {
  const can = useCan()
  const manage = can('timetables.manage')
  const list = useListParams({ defaultSort: 'name', filters: ['type', 'is_active'] })
  const timetables = useTimetables(list.params)
  const duplicate = useDuplicateTimetable()
  const remove = useDeleteTimetable()
  const [editing, setEditing] = useState<number | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Timetable | null>(null)

  const columns: Column<Timetable>[] = [
    {
      key: 'name',
      header: 'Timetable',
      sortKey: 'name',
      cell: (t) => (
        <div className="flex items-center gap-2.5">
          <ColorDot color={t.color} />
          <div className="min-w-0">
            <p className="truncate font-medium">{t.name}</p>
            <p className="text-muted-foreground font-mono text-xs">{t.code}</p>
          </div>
        </div>
      ),
    },
    {
      key: 'hours',
      header: 'Hours',
      sortKey: 'start_minute',
      cell: (t) => (
        <div>
          <p className="text-sm tabular-nums">{timeRange(t.start_minute, t.duration_minutes)}</p>
          {t.type === 'flexible' && t.required_minutes !== null && (
            <p className="text-muted-foreground text-xs">{formatDuration(t.required_minutes)} required</p>
          )}
        </div>
      ),
    },
    {
      key: 'type',
      header: 'Type',
      hideBelow: 'sm',
      cell: (t) => (
        <Badge variant="secondary" className="font-normal capitalize">
          {t.type}
        </Badge>
      ),
    },
    {
      key: 'work',
      header: 'Work time',
      hideBelow: 'md',
      cell: (t) => (
        <span className="text-sm tabular-nums">{t.work_minutes !== undefined ? formatDuration(t.work_minutes) : '—'}</span>
      ),
    },
    {
      key: 'breaks',
      header: 'Breaks',
      hideBelow: 'lg',
      cell: (t) => <span className="text-muted-foreground text-sm">{t.breaks?.length ? String(t.breaks.length) : '—'}</span>,
    },
    {
      key: 'shifts',
      header: 'Shifts',
      hideBelow: 'lg',
      cell: (t) => <span className="tabular-nums">{t.shifts_count ?? 0}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (t) => <StatusBadge tone={t.is_active ? 'success' : 'neutral'}>{t.is_active ? 'active' : 'inactive'}</StatusBadge>,
    },
    {
      key: 'actions',
      header: '',
      className: 'w-12 text-right',
      cell: (t) =>
        manage ? (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="size-8" aria-label={`Actions for ${t.name}`}>
                <MoreHorizontal />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onSelect={() => setEditing(t.id)}>
                <Pencil /> Edit
              </DropdownMenuItem>
              <DropdownMenuItem
                onSelect={() =>
                  duplicate.mutate(t.id, {
                    onSuccess: (copy) => {
                      toast.success(`Created ${copy.data.name}.`)
                      setEditing(copy.data.id)
                    },
                    onError: (e) => toast.error(errorMessage(e)),
                  })
                }
              >
                <Copy /> Duplicate
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem variant="destructive" disabled={(t.shifts_count ?? 0) > 0} onSelect={() => setDeleting(t)}>
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
        title="Timetables"
        description="Work periods: fixed hours or a flexible window, with breaks and grace."
        actions={
          manage && (
            <Button onClick={() => setEditing('new')}>
              <Plus /> New timetable
            </Button>
          )
        }
      />
      <div className="flex flex-col gap-2 sm:flex-row">
        <SearchInput value={list.search} onChange={list.setSearch} label="Search timetables" />
        <Select value={list.filter('type') || ALL} onValueChange={(v) => list.setFilter('type', v === ALL ? '' : v)}>
          <SelectTrigger className="w-full sm:w-40" aria-label="Filter by type">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All types</SelectItem>
            <SelectItem value="fixed">Fixed</SelectItem>
            <SelectItem value="flexible">Flexible</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <DataTable
        caption="Timetables"
        columns={columns}
        list={list}
        query={timetables}
        rowKey={(t) => t.id}
        onRowClick={manage ? (t) => setEditing(t.id) : undefined}
        emptyTitle="No timetables yet"
        emptyDescription="Create the work periods your shifts are built from, like 09:00–18:00."
        emptyAction={manage ? <Button onClick={() => setEditing('new')}>New timetable</Button> : undefined}
      />
      <TimetableFormDialog target={editing} onClose={() => setEditing(null)} onDuplicated={setEditing} />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={`Delete ${deleting?.name ?? 'timetable'}?`}
        description={<p>Timetables that are part of a shift cannot be deleted; deactivate them instead.</p>}
        confirmLabel="Delete timetable"
        destructive
        pending={remove.isPending}
        onConfirm={() => {
          if (!deleting) return
          remove.mutate(deleting.id, {
            onSuccess: () => {
              toast.success('Timetable deleted.')
              setDeleting(null)
            },
            onError: (e) => toast.error(errorMessage(e)),
          })
        }}
      />
    </>
  )
}
