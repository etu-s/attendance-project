import { EmptyState, ErrorState, PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { employeeStatusTone, useMyEmployee } from './api'
import { EmployeeDetails } from './employee-detail-page'

/** Self-service view of the signed-in user's own employee record (mobile-first). */
export function MyProfilePage() {
  const prefs = useDisplayPreferences()
  const me = useMyEmployee()

  return (
    <>
      <PageHeader title="My profile" description="Your employee record as HR has it. Ask HR to correct anything that is wrong." />
      {me.isError ? (
        <ErrorState error={me.error} onRetry={() => me.refetch()} />
      ) : me.isPending ? (
        <Skeleton className="h-56 w-full" />
      ) : me.data === null ? (
        <EmptyState
          title="No employee record linked"
          description="Your login is not linked to an employee record yet. Ask HR to link it."
        />
      ) : (
        <Card>
          <CardContent className="space-y-6 pt-6">
            <div className="flex flex-wrap items-center gap-3">
              <p className="text-lg font-semibold">{me.data.full_name}</p>
              <StatusBadge tone={employeeStatusTone[me.data.status]}>{me.data.status}</StatusBadge>
            </div>
            <EmployeeDetails employee={me.data} prefs={prefs} />
          </CardContent>
        </Card>
      )}
    </>
  )
}
