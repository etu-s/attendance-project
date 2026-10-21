import { useState } from 'react'
import { Link2, Loader2, XCircle } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { DataTable, type Column } from '@/components/data-table'
import { SearchInput } from '@/components/search-input'
import { PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useEmployees } from '@/features/employees/api'
import { useCan, useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { formatDateTime } from '@/lib/format'
import { errorMessage } from '@/lib/forms'
import { useDismissOrphan, useLinkOrphan, useOrphanPunches, type OrphanPunch, type OrphanStatus } from './api'

function LinkDialog({ orphan, onClose }: { orphan: OrphanPunch | null; onClose: () => void }) {
  const [search, setSearch] = useState('')
  const [employeeId, setEmployeeId] = useState('')
  const employees = useEmployees({ search, per_page: 50, sort: 'name', status: 'active' })
  const link = useLinkOrphan()

  if (!orphan) {
    return null
  }

  const close = () => {
    setEmployeeId('')
    setSearch('')
    onClose()
  }

  return (
    <Dialog open onOpenChange={(open) => !open && close()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Link “{orphan.employee_identifier}”</DialogTitle>
          <DialogDescription>
            It becomes one of the employee&apos;s punch identifiers: every unmatched punch with it, and every future one, counts
            for them.
          </DialogDescription>
        </DialogHeader>
        <div className="grid gap-2">
          <SearchInput value={search} onChange={setSearch} label="Search employees" />
          <Label htmlFor="link-employee" className="sr-only">
            Employee
          </Label>
          <Select value={employeeId} onValueChange={setEmployeeId}>
            <SelectTrigger id="link-employee" className="w-full">
              <SelectValue placeholder={employees.isPending ? 'Loading…' : 'Choose an employee'} />
            </SelectTrigger>
            <SelectContent>
              {(employees.data?.data ?? []).map((e) => (
                <SelectItem key={e.id} value={String(e.id)}>
                  {e.full_name} · {e.employee_code}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={close}>
            Cancel
          </Button>
          <Button
            disabled={employeeId === '' || link.isPending}
            onClick={() =>
              link.mutate(
                { id: orphan.id, employeeId: Number(employeeId) },
                {
                  onSuccess: (r) => {
                    toast.success(
                      `Linked ${String(r.linked)} punch${r.linked === 1 ? '' : 'es'}. Attendance is being recalculated.`,
                    )
                    close()
                  },
                  onError: (e) => toast.error(errorMessage(e)),
                },
              )
            }
          >
            {link.isPending && <Loader2 className="animate-spin" />}
            Link
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

const tabs: { value: OrphanStatus; label: string }[] = [
  { value: 'open', label: 'Open' },
  { value: 'resolved', label: 'Linked' },
  { value: 'dismissed', label: 'Dismissed' },
]

/** Punches whose identifier matched no employee: link them or dismiss them. */
export function OrphanPunchesPage() {
  const can = useCan()
  const prefs = useDisplayPreferences()
  const manage = can('punches.manage')
  const list = useListParams({ defaultSort: '-created_at', filters: ['status'], defaultPerPage: 25 })
  const status = (list.filter('status') || 'open') as OrphanStatus
  const orphans = useOrphanPunches({ ...list.params, status })
  const dismiss = useDismissOrphan()
  const [linking, setLinking] = useState<OrphanPunch | null>(null)
  const [dismissing, setDismissing] = useState<OrphanPunch | null>(null)

  const columns: Column<OrphanPunch>[] = [
    {
      key: 'identifier',
      header: 'Identifier',
      sortKey: 'employee_identifier',
      cell: (o) => <span className="font-mono text-sm">{o.employee_identifier}</span>,
    },
    {
      key: 'punched',
      header: 'Punched at',
      cell: (o) => <span className="tabular-nums">{formatDateTime(o.punched_at, prefs)}</span>,
    },
    { key: 'source', header: 'Source', hideBelow: 'sm', cell: (o) => o.source.name },
    {
      key: 'state',
      header: status === 'open' ? 'Note' : 'Outcome',
      hideBelow: 'md',
      cell: (o) =>
        o.employee ? (
          <span className="text-sm">{o.employee.full_name}</span>
        ) : o.too_old ? (
          <StatusBadge tone="neutral">too old to count</StatusBadge>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: 'actions',
      header: '',
      className: 'w-44 text-right',
      cell: (o) =>
        manage && o.status === 'open' ? (
          <div className="flex justify-end gap-1">
            <Button variant="outline" size="sm" onClick={() => setLinking(o)}>
              <Link2 /> Link
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="size-8"
              aria-label={`Dismiss ${o.employee_identifier}`}
              onClick={() => setDismissing(o)}
            >
              <XCircle />
            </Button>
          </div>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader
        title="Unmatched punches"
        description="Punches from badges or codes that belong to no employee. Nothing is dropped; link them to count them."
      />
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <Tabs value={status} onValueChange={(v) => list.setFilter('status', v === 'open' ? '' : v)}>
          <TabsList>
            {tabs.map((t) => (
              <TabsTrigger key={t.value} value={t.value}>
                {t.label}
              </TabsTrigger>
            ))}
          </TabsList>
        </Tabs>
        <SearchInput value={list.search} onChange={list.setSearch} label="Search identifiers" />
      </div>
      <DataTable
        caption="Unmatched punches"
        columns={columns}
        list={list}
        query={orphans}
        rowKey={(o) => o.id}
        emptyTitle={
          status === 'open'
            ? 'Every punch is matched'
            : `No ${tabs.find((t) => t.value === status)?.label.toLowerCase() ?? ''} punches`
        }
        emptyDescription={status === 'open' ? 'Punches with unknown identifiers will appear here.' : undefined}
      />
      <LinkDialog orphan={linking} onClose={() => setLinking(null)} />
      <ConfirmDialog
        open={dismissing !== null}
        onOpenChange={(open) => !open && setDismissing(null)}
        title={`Dismiss ${dismissing?.employee_identifier ?? 'punch'}?`}
        description={<p>Use this for test badges or visitors. The punch stays in the log but never counts.</p>}
        confirmLabel="Dismiss"
        pending={dismiss.isPending}
        onConfirm={() => {
          if (!dismissing) return
          dismiss.mutate(dismissing.id, {
            onSuccess: () => {
              toast.success('Punch dismissed.')
              setDismissing(null)
            },
            onError: (e) => toast.error(errorMessage(e)),
          })
        }}
      />
    </>
  )
}
