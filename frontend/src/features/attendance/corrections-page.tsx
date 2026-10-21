import { useState } from 'react'
import { Check, Loader2, X } from 'lucide-react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { DataTable, type Column } from '@/components/data-table'
import { SearchInput } from '@/components/search-input'
import { PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Textarea } from '@/components/ui/textarea'
import { useCan, useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { formatDateTime, formatLocalDate, formatTime } from '@/lib/format'
import { errorMessage } from '@/lib/forms'
import { useCorrections, useDecideCorrection, type CorrectionStatus, type PunchCorrection } from './api'
import { correctionStatusTone } from './status'

type Decision = { correction: PunchCorrection; decision: 'approve' | 'reject' }

function DecisionDialog({ target, onClose }: { target: Decision | null; onClose: () => void }) {
  const decide = useDecideCorrection()
  const [note, setNote] = useState('')

  if (!target) {
    return null
  }

  const approve = target.decision === 'approve'

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open) {
          setNote('')
          onClose()
        }
      }}
    >
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{approve ? 'Approve correction' : 'Reject correction'}</DialogTitle>
          <DialogDescription>
            {target.correction.employee.full_name} · {target.correction.reason}
          </DialogDescription>
        </DialogHeader>
        <div className="grid gap-1.5">
          <Label htmlFor="decision-note">Note (optional)</Label>
          <Textarea id="decision-note" rows={3} maxLength={255} value={note} onChange={(e) => setNote(e.target.value)} />
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button
            variant={approve ? 'default' : 'destructive'}
            disabled={decide.isPending}
            onClick={() =>
              decide.mutate(
                { id: target.correction.id, decision: target.decision, note: note.trim() || null },
                {
                  onSuccess: () => {
                    toast.success(approve ? 'Approved. Attendance is being recalculated.' : 'Rejected.')
                    setNote('')
                    onClose()
                  },
                  onError: (e) => toast.error(errorMessage(e)),
                },
              )
            }
          >
            {decide.isPending && <Loader2 className="animate-spin" />}
            {approve ? 'Approve' : 'Reject'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

const statusTabs: CorrectionStatus[] = ['pending', 'approved', 'rejected']

/** Correction requests of the people the viewer can see (employees: their own). */
export function CorrectionsPage() {
  const can = useCan()
  const prefs = useDisplayPreferences()
  const list = useListParams({ defaultSort: '-created_at', filters: ['status'], defaultPerPage: 25 })
  const status = (list.filter('status') || 'pending') as CorrectionStatus
  const corrections = useCorrections({ ...list.params, status })
  const [deciding, setDeciding] = useState<Decision | null>(null)
  const approver = can('corrections.approve')

  const columns: Column<PunchCorrection>[] = [
    {
      key: 'employee',
      header: 'Employee',
      cell: (c) =>
        can('employees.view') || can('employees.view-all') ? (
          <Link to={`/employees/${String(c.employee.id)}`} className="font-medium underline-offset-4 hover:underline">
            {c.employee.full_name}
          </Link>
        ) : (
          <span className="font-medium">{c.employee.full_name}</span>
        ),
    },
    {
      key: 'date',
      header: 'Work date',
      sortKey: 'work_date',
      cell: (c) => <span className="tabular-nums">{formatLocalDate(c.work_date, prefs)}</span>,
    },
    {
      key: 'change',
      header: 'Change',
      cell: (c) =>
        c.kind === 'add' ? (
          <span>Add punch {formatDateTime(c.punched_at, prefs)}</span>
        ) : (
          <span>Ignore punch {formatTime(c.raw_punch?.punched_at, prefs)}</span>
        ),
    },
    { key: 'reason', header: 'Reason', hideBelow: 'md', cell: (c) => <span className="text-muted-foreground">{c.reason}</span> },
    {
      key: 'requested',
      header: 'Requested',
      sortKey: 'created_at',
      hideBelow: 'lg',
      cell: (c) => (
        <div className="text-xs">
          <p>{c.requested_by?.name ?? '—'}</p>
          <p className="text-muted-foreground">{formatDateTime(c.created_at, prefs)}</p>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (c) => (
        <div className="space-y-1">
          <StatusBadge tone={correctionStatusTone[c.status]}>{c.status}</StatusBadge>
          {c.decision_note && <p className="text-muted-foreground text-xs">{c.decision_note}</p>}
        </div>
      ),
    },
    {
      key: 'actions',
      header: '',
      className: 'w-24 text-right',
      cell: (c) =>
        c.can_decide ? (
          <div className="flex justify-end gap-1">
            <Button
              variant="ghost"
              size="icon"
              className="size-8"
              aria-label={`Approve correction for ${c.employee.full_name}`}
              onClick={() => setDeciding({ correction: c, decision: 'approve' })}
            >
              <Check />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="size-8"
              aria-label={`Reject correction for ${c.employee.full_name}`}
              onClick={() => setDeciding({ correction: c, decision: 'reject' })}
            >
              <X />
            </Button>
          </div>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader
        title="Corrections"
        description={
          approver
            ? 'Missed or wrong punches reported by employees and managers. Approving recalculates the day.'
            : 'Your correction requests. Open a day in My attendance to make a new one.'
        }
      />
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <Tabs value={status} onValueChange={(v) => list.setFilter('status', v === 'pending' ? '' : v)}>
          <TabsList>
            {statusTabs.map((s) => (
              <TabsTrigger key={s} value={s} className="capitalize">
                {s}
              </TabsTrigger>
            ))}
          </TabsList>
        </Tabs>
        <SearchInput value={list.search} onChange={list.setSearch} label="Search employees" />
      </div>
      <DataTable
        caption="Corrections"
        columns={columns}
        list={list}
        query={corrections}
        rowKey={(c) => c.id}
        emptyTitle={status === 'pending' ? 'Nothing waiting' : `No ${status} corrections`}
        emptyDescription={status === 'pending' ? 'New requests appear here for approval.' : undefined}
      />
      <DecisionDialog target={deciding} onClose={() => setDeciding(null)} />
    </>
  )
}
