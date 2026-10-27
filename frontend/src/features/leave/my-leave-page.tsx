import { useState } from 'react'
import { Plus } from 'lucide-react'
import { DataTable } from '@/components/data-table'
import { EmptyState, PageHeader } from '@/components/states'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useMyEmployee } from '@/features/employees/api'
import { useCan, useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { formatLocalDate } from '@/lib/format'
import { useLeaveRequests, useMyLeaveBalances } from './api'
import { BalanceCards } from './balance-cards'
import { LeaveActionDialog, type LeaveAction } from './leave-action-dialog'
import { useLeaveColumns } from './leave-columns'
import { LeaveRequestDialog } from './leave-request-dialog'

/** The signed-in employee's balances and requests (works on phones). */
export function MyLeavePage() {
  const can = useCan()
  const prefs = useDisplayPreferences()
  const me = useMyEmployee()
  const balances = useMyLeaveBalances()
  const list = useListParams({ defaultSort: '-start_date', defaultPerPage: 10 })
  const requests = useLeaveRequests({ ...list.params, mine: 1 })
  const [requesting, setRequesting] = useState(false)
  const [action, setAction] = useState<LeaveAction | null>(null)
  const columns = useLeaveColumns(setAction, { showEmployee: false })

  if (me.isPending) {
    return <Skeleton className="h-64 w-full" />
  }

  if (me.data === null) {
    return (
      <>
        <PageHeader title="My leave" />
        <EmptyState
          title="No employee record linked"
          description="Your login is not linked to an employee record yet. Ask HR to link it."
        />
      </>
    )
  }

  const year = balances.data
  return (
    <>
      <PageHeader
        title="My leave"
        description={
          year ? `Leave year ${formatLocalDate(year.year_start, prefs)} – ${formatLocalDate(year.year_end, prefs)}.` : undefined
        }
        actions={
          can('leave.request') && (
            <Button onClick={() => setRequesting(true)}>
              <Plus /> Request leave
            </Button>
          )
        }
      />
      <BalanceCards
        balances={balances.data?.data}
        loading={balances.isPending}
        error={balances.isError ? balances.error : null}
        onRetry={() => balances.refetch()}
      />
      <section className="space-y-3" aria-labelledby="my-requests">
        <h2 id="my-requests" className="text-lg font-semibold">
          My requests
        </h2>
        <DataTable
          caption="My leave requests"
          columns={columns}
          list={list}
          query={requests}
          rowKey={(r) => r.id}
          emptyTitle="No leave requested yet"
          emptyDescription="Requests and their decisions appear here."
          emptyAction={can('leave.request') ? <Button onClick={() => setRequesting(true)}>Request leave</Button> : undefined}
        />
      </section>
      <LeaveRequestDialog open={requesting} employeeId={me.data?.id ?? null} onClose={() => setRequesting(false)} />
      <LeaveActionDialog target={action} onClose={() => setAction(null)} />
    </>
  )
}
