import { useState } from 'react'
import { DataTable, type Column } from '@/components/data-table'
import { PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { defaultPreferences, formatDateTime, type DisplayPreferences } from '@/lib/format'
import { auditActions, useAuditLogs, type AuditAction, type AuditLog, type AuditScope } from './api'

const ALL = 'all'

const actionTone: Record<AuditAction, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  created: 'success',
  updated: 'info',
  deleted: 'danger',
  restored: 'warning',
  role_changed: 'warning',
}

const subjectTypes = ['user', 'setting'] as const

function actorLabel(log: AuditLog): string {
  if (log.actor.type === 'system') return 'System'
  if (log.actor.type === 'super_admin') return `${log.actor.name ?? 'Super admin'} (platform)`

  return log.actor.name ?? `User #${log.actor.id ?? '?'}`
}

function scalar(value: unknown): string | null {
  return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean' ? String(value) : null
}

function display(value: unknown): string {
  if (value === null || value === undefined) return '—'
  if (typeof value === 'string') {
    // Setting values are stored JSON-encoded; show the decoded scalar.
    try {
      return scalar(JSON.parse(value) as unknown) ?? value
    } catch {
      return value
    }
  }

  return scalar(value) ?? JSON.stringify(value)
}

function changedFields(log: AuditLog): string[] {
  return [...new Set([...Object.keys(log.before ?? {}), ...Object.keys(log.after ?? {})])]
}

/** The workspace audit log (times in the workspace's display preferences). */
export function AuditLogPage() {
  const prefs = useDisplayPreferences()

  return (
    <AuditLogView scope="tenant" prefs={prefs} description="Who changed what, and when. Entries cannot be edited or removed." />
  )
}

/** The central audit log of the super admin console (plans, tenants, admins; UTC). */
export function CentralAuditLogPage() {
  return (
    <AuditLogView
      scope="central"
      prefs={{ ...defaultPreferences, timezone: 'UTC' }}
      description="Changes to plans, tenants and super admins. Times and date filters are UTC."
    />
  )
}

function AuditLogView({ scope, prefs, description }: { scope: AuditScope; prefs: DisplayPreferences; description: string }) {
  const list = useListParams({
    defaultSort: '-created_at',
    filters: ['action', 'subject_type', 'from', 'to'],
    defaultPerPage: 25,
  })
  const logs = useAuditLogs(list.params, scope)
  const [selected, setSelected] = useState<AuditLog | null>(null)

  const columns: Column<AuditLog>[] = [
    {
      key: 'created_at',
      header: 'When',
      sortKey: 'created_at',
      cell: (log) => <span className="text-sm whitespace-nowrap tabular-nums">{formatDateTime(log.created_at, prefs)}</span>,
    },
    {
      key: 'action',
      header: 'Action',
      cell: (log) => <StatusBadge tone={actionTone[log.action]}>{log.action.replace('_', ' ')}</StatusBadge>,
    },
    {
      key: 'subject',
      header: 'Record',
      cell: (log) => (
        <span className="text-sm">
          <span className="capitalize">{log.subject.type.replace('_', ' ')}</span>{' '}
          <span className="text-muted-foreground">#{log.subject.id}</span>
        </span>
      ),
    },
    { key: 'actor', header: 'By', hideBelow: 'sm', cell: (log) => <span className="text-sm">{actorLabel(log)}</span> },
    {
      key: 'fields',
      header: 'Fields',
      hideBelow: 'md',
      cell: (log) => <span className="text-muted-foreground text-sm">{changedFields(log).slice(0, 4).join(', ') || '—'}</span>,
    },
    {
      key: 'view',
      header: '',
      className: 'w-16 text-right',
      // Rows are also clickable with a mouse; this button is the keyboard/screen-reader path.
      cell: (log) => (
        <Button
          variant="ghost"
          size="sm"
          onClick={(event) => {
            event.stopPropagation()
            setSelected(log)
          }}
          aria-label={`View details of ${log.action.replace('_', ' ')} on ${log.subject.type} #${log.subject.id}`}
        >
          View
        </Button>
      ),
    },
  ]

  return (
    <>
      <PageHeader title="Audit log" description={description} />

      <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
        <div className="grid gap-1.5">
          <Label htmlFor="audit-action">Action</Label>
          <Select value={list.filter('action') || ALL} onValueChange={(v) => list.setFilter('action', v === ALL ? '' : v)}>
            <SelectTrigger id="audit-action" className="w-full sm:w-40">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>All actions</SelectItem>
              {auditActions.map((action) => (
                <SelectItem key={action} value={action} className="capitalize">
                  {action.replace('_', ' ')}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="audit-subject">Record type</Label>
          <Select
            value={list.filter('subject_type') || ALL}
            onValueChange={(v) => list.setFilter('subject_type', v === ALL ? '' : v)}
          >
            <SelectTrigger id="audit-subject" className="w-full sm:w-40">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>All records</SelectItem>
              {subjectTypes.map((type) => (
                <SelectItem key={type} value={type} className="capitalize">
                  {type}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="audit-from">From</Label>
          <Input
            id="audit-from"
            type="date"
            value={list.filter('from')}
            onChange={(e) => list.setFilter('from', e.target.value)}
          />
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="audit-to">To</Label>
          <Input
            id="audit-to"
            type="date"
            value={list.filter('to')}
            min={list.filter('from') || undefined}
            onChange={(e) => list.setFilter('to', e.target.value)}
          />
        </div>
      </div>

      <DataTable
        caption="Audit log"
        columns={columns}
        list={list}
        query={logs}
        rowKey={(log) => log.id}
        onRowClick={setSelected}
        emptyTitle="Nothing recorded yet"
        emptyDescription="Changes to users and settings will appear here."
      />

      <Dialog open={selected !== null} onOpenChange={(open) => !open && setSelected(null)}>
        <DialogContent className="sm:max-w-2xl">
          {selected && (
            <>
              <DialogHeader>
                <DialogTitle className="capitalize">
                  {selected.action.replace('_', ' ')} · {selected.subject.type} #{selected.subject.id}
                </DialogTitle>
                <DialogDescription>
                  {actorLabel(selected)} · {formatDateTime(selected.created_at, prefs)}
                  {selected.ip_address && ` · ${selected.ip_address}`}
                </DialogDescription>
              </DialogHeader>
              <div className="max-h-[60vh] overflow-auto rounded-md border">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Field</TableHead>
                      <TableHead>Before</TableHead>
                      <TableHead>After</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {changedFields(selected).map((field) => (
                      <TableRow key={field}>
                        <TableCell className="font-mono text-xs">{field}</TableCell>
                        <TableCell className="text-muted-foreground text-sm break-all whitespace-normal">
                          {display(selected.before?.[field])}
                        </TableCell>
                        <TableCell className="text-sm break-all whitespace-normal">{display(selected.after?.[field])}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>
    </>
  )
}
