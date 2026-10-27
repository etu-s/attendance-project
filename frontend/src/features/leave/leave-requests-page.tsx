import { useState } from 'react'
import { Plus } from 'lucide-react'
import { DataTable } from '@/components/data-table'
import { SearchInput } from '@/components/search-input'
import { PageHeader } from '@/components/states'
import { Button } from '@/components/ui/button'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useCan } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { useLeaveRequests, type LeaveRequestStatus } from './api'
import { LeaveActionDialog, type LeaveAction } from './leave-action-dialog'
import { useLeaveColumns } from './leave-columns'
import { LeaveRequestDialog } from './leave-request-dialog'

const tabs: LeaveRequestStatus[] = ['pending', 'approved', 'rejected', 'cancelled']

/** Approvers' inbox: requests of the people the viewer can see. */
export function LeaveRequestsPage() {
  const can = useCan()
  const list = useListParams({ defaultSort: 'start_date', filters: ['status'], defaultPerPage: 25 })
  const status = (list.filter('status') || 'pending') as LeaveRequestStatus
  const requests = useLeaveRequests({ ...list.params, status })
  const [action, setAction] = useState<LeaveAction | null>(null)
  const [recording, setRecording] = useState(false)
  const columns = useLeaveColumns(setAction, { showEmployee: true })

  return (
    <>
      <PageHeader
        title="Leave requests"
        description="Approving takes the days from the balance; holidays and days off inside the range are never counted."
        actions={
          can('leave.manage') && (
            <Button onClick={() => setRecording(true)}>
              <Plus /> Record leave
            </Button>
          )
        }
      />
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <Tabs value={status} onValueChange={(v) => list.setFilter('status', v === 'pending' ? '' : v)}>
          <TabsList>
            {tabs.map((t) => (
              <TabsTrigger key={t} value={t} className="capitalize">
                {t}
              </TabsTrigger>
            ))}
          </TabsList>
        </Tabs>
        <SearchInput value={list.search} onChange={list.setSearch} label="Search employees" />
      </div>
      <DataTable
        caption="Leave requests"
        columns={columns}
        list={list}
        query={requests}
        rowKey={(r) => r.id}
        emptyTitle={status === 'pending' ? 'Nothing waiting' : `No ${status} requests`}
        emptyDescription={status === 'pending' ? 'New requests appear here for a decision.' : undefined}
      />
      <LeaveActionDialog target={action} onClose={() => setAction(null)} />
      <LeaveRequestDialog open={recording} employeeId={null} onClose={() => setRecording(false)} />
    </>
  )
}
