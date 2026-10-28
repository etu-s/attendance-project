import { RotateCw } from 'lucide-react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { DataTable, type Column } from '@/components/data-table'
import { SearchInput } from '@/components/search-input'
import { PageHeader } from '@/components/states'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { monthEnd, monthStart } from '@/features/scheduling/dates'
import { useCan, useSession } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { todayIn } from '@/lib/format'
import { errorMessage } from '@/lib/forms'
import { useOvertimeSummary, useRecalculateOvertime, type OvertimeSummaryRow } from './api'
import { formatDuration } from './format'

/**
 * Approved overtime per employee over a range (this month by default):
 * minutes, weighted minutes (× rate) and the split by kind of day.
 */
export function OvertimeSummaryPage() {
  const can = useCan()
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  const list = useListParams({ defaultSort: '-work_date', filters: ['from', 'to'], defaultPerPage: 25 })
  const from = list.filter('from') || monthStart(today)
  const to = list.filter('to') || monthEnd(monthStart(today))
  const summary = useOvertimeSummary({ ...list.params, from, to })
  const recalculate = useRecalculateOvertime()

  const columns: Column<OvertimeSummaryRow>[] = [
    {
      key: 'employee',
      header: 'Employee',
      cell: (r) =>
        r.employee ? (
          <div>
            <Link to={`/employees/${String(r.employee.id)}`} className="font-medium underline-offset-4 hover:underline">
              {r.employee.full_name}
            </Link>
            <p className="text-muted-foreground text-xs">{r.employee.employee_code}</p>
          </div>
        ) : (
          'Deleted'
        ),
    },
    {
      key: 'approved',
      header: 'Approved',
      cell: (r) => <span className="font-medium tabular-nums">{formatDuration(r.approved_minutes)}</span>,
    },
    {
      key: 'weighted',
      header: 'Weighted',
      cell: (r) => <span className="tabular-nums">{formatDuration(r.approved_weighted_minutes)}</span>,
    },
    {
      key: 'workday',
      header: 'Working days',
      hideBelow: 'md',
      cell: (r) => <span className="tabular-nums">{formatDuration(r.approved_workday_minutes)}</span>,
    },
    {
      key: 'rest',
      header: 'Rest days',
      hideBelow: 'md',
      cell: (r) => <span className="tabular-nums">{formatDuration(r.approved_rest_day_minutes)}</span>,
    },
    {
      key: 'holiday',
      header: 'Holidays',
      hideBelow: 'lg',
      cell: (r) => <span className="tabular-nums">{formatDuration(r.approved_holiday_minutes)}</span>,
    },
    {
      key: 'pending',
      header: 'Pending',
      cell: (r) =>
        r.pending_count > 0 ? (
          <span className="text-status-late tabular-nums">
            {formatDuration(r.pending_minutes)} ({r.pending_count})
          </span>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Overtime summary"
        description="Weighted = minutes × rate. Pending overtime is not included in the approved totals."
        actions={
          can('overtime.manage') && (
            <Button
              variant="outline"
              disabled={recalculate.isPending}
              onClick={() =>
                recalculate.mutate(
                  { from, to: to > today ? today : to },
                  {
                    onSuccess: () => toast.success('Recalculation queued (uses the current overtime settings).'),
                    onError: (e) => toast.error(errorMessage(e)),
                  },
                )
              }
            >
              <RotateCw className={recalculate.isPending ? 'animate-spin' : undefined} /> Recalculate range
            </Button>
          )
        }
      />
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div className="grid gap-1.5">
          <Label htmlFor="sum-from">From</Label>
          <Input
            id="sum-from"
            type="date"
            className="w-40"
            value={from}
            max={to}
            onChange={(e) => list.setFilter('from', e.target.value)}
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="sum-to">To</Label>
          <Input
            id="sum-to"
            type="date"
            className="w-40"
            value={to}
            min={from}
            onChange={(e) => list.setFilter('to', e.target.value)}
          />
        </div>
        <SearchInput value={list.search} onChange={list.setSearch} label="Search employees" />
      </div>
      <DataTable
        caption="Overtime per employee"
        columns={columns}
        list={list}
        query={summary}
        rowKey={(r) => r.employee?.id ?? 0}
        emptyTitle="No overtime in this range"
        emptyDescription="Approved and pending overtime per employee appears here."
      />
    </>
  )
}
