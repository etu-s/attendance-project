import { Link } from 'react-router'
import { DataTable, type Column } from '@/components/data-table'
import { SearchInput } from '@/components/search-input'
import { PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useCan, useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { formatDateTime } from '@/lib/format'
import { usePunchSources, useRawPunches, type RawPunch } from './api'
import { rawPunchStateLabel } from './status'

const stateTone = {
  matched: 'success',
  unmatched: 'warning',
  dismissed: 'neutral',
  resolved: 'success',
  pending: 'neutral',
} as const

/**
 * Every punch exactly as received (read-only: raw punches are never edited
 * or deleted). Times in the workspace timezone; dates filter on UTC days.
 */
export function RawPunchesPage() {
  const can = useCan()
  const prefs = useDisplayPreferences()
  const list = useListParams({
    defaultSort: '-punched_at',
    filters: ['state', 'punch_source_id', 'from', 'to'],
    defaultPerPage: 25,
  })
  const punches = useRawPunches(list.params)
  const sources = usePunchSources()
  const showSources = can('punch-sources.manage')

  const columns: Column<RawPunch>[] = [
    {
      key: 'punched',
      header: 'Punched at',
      sortKey: 'punched_at',
      cell: (p) => <span className="tabular-nums">{formatDateTime(p.punched_at, prefs)}</span>,
    },
    {
      key: 'identifier',
      header: 'Identifier',
      cell: (p) => (
        <div>
          <p className="font-mono text-sm">{p.employee_identifier}</p>
          {p.employee && (
            <Link
              to={`/employees/${String(p.employee.id)}`}
              className="text-muted-foreground text-xs underline-offset-4 hover:underline"
            >
              {p.employee.full_name}
            </Link>
          )}
        </div>
      ),
    },
    {
      key: 'state',
      header: 'State',
      cell: (p) => (
        <div className="flex flex-wrap gap-1">
          <StatusBadge tone={stateTone[p.state]}>{rawPunchStateLabel[p.state]}</StatusBadge>
          {p.too_old && <StatusBadge tone="neutral">too old</StatusBadge>}
        </div>
      ),
    },
    {
      key: 'direction',
      header: 'Direction',
      hideBelow: 'md',
      cell: (p) => <span className="capitalize">{p.direction ?? '—'}</span>,
    },
    { key: 'source', header: 'Source', hideBelow: 'sm', cell: (p) => p.source.name },
    {
      key: 'received',
      header: 'Received',
      sortKey: 'received_at',
      hideBelow: 'lg',
      cell: (p) => <span className="text-muted-foreground text-xs tabular-nums">{formatDateTime(p.received_at, prefs)}</span>,
    },
  ]

  return (
    <>
      <PageHeader
        title="Punch log"
        description="Every punch as it arrived. Corrections are separate; these records never change."
      />
      <div className="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end">
        <SearchInput value={list.search} onChange={list.setSearch} label="Search identifiers" />
        <div className="grid gap-1.5">
          <Label htmlFor="raw-state">State</Label>
          <Select value={list.filter('state') || 'all'} onValueChange={(v) => list.setFilter('state', v === 'all' ? '' : v)}>
            <SelectTrigger id="raw-state" className="w-40">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All</SelectItem>
              <SelectItem value="matched">Matched</SelectItem>
              <SelectItem value="unmatched">Unmatched</SelectItem>
              <SelectItem value="too_old">Too old</SelectItem>
            </SelectContent>
          </Select>
        </div>
        {showSources && (
          <div className="grid gap-1.5">
            <Label htmlFor="raw-source">Source</Label>
            <Select
              value={list.filter('punch_source_id') || 'all'}
              onValueChange={(v) => list.setFilter('punch_source_id', v === 'all' ? '' : v)}
            >
              <SelectTrigger id="raw-source" className="w-44">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All sources</SelectItem>
                {(sources.data ?? []).map((s) => (
                  <SelectItem key={s.id} value={String(s.id)}>
                    {s.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}
        <div className="grid gap-1.5">
          <Label htmlFor="raw-from">From (UTC)</Label>
          <Input
            id="raw-from"
            type="date"
            className="w-40"
            value={list.filter('from')}
            onChange={(e) => list.setFilter('from', e.target.value)}
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="raw-to">To (UTC)</Label>
          <Input
            id="raw-to"
            type="date"
            className="w-40"
            value={list.filter('to')}
            onChange={(e) => list.setFilter('to', e.target.value)}
          />
        </div>
      </div>
      <DataTable
        caption="Raw punches"
        columns={columns}
        list={list}
        query={punches}
        rowKey={(p) => p.id}
        emptyTitle="No punches received yet"
        emptyDescription="Create a punch source and point a device at POST /api/v1/punches, or run php artisan punches:simulate."
      />
    </>
  )
}
