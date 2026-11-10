import { Download, Loader2 } from 'lucide-react'
import { DataTable, type Column } from '@/components/data-table'
import { PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { formatDateTime } from '@/lib/format'
import { exportDownloadUrl, useReportExports, type ExportStatus, type ReportExport } from './api'
import { formatBytes } from './format'

const statusTone: Record<ExportStatus, 'success' | 'warning' | 'danger' | 'neutral'> = {
  queued: 'neutral',
  running: 'neutral',
  completed: 'success',
  failed: 'danger',
  expired: 'neutral',
}

const statusLabel: Record<ExportStatus, string> = {
  queued: 'Queued',
  running: 'Generating',
  completed: 'Ready',
  failed: 'Failed',
  expired: 'Expired',
}

function describe(params: ReportExport['params']): string {
  if (params.month) return String(params.month)

  return `${String(params.from ?? '')} – ${String(params.to ?? '')}`
}

/** The user's own report files. Generated in the background; deleted after 7 days. */
export function ExportsPage() {
  const prefs = useDisplayPreferences()
  const list = useListParams({ defaultSort: '-created_at', defaultPerPage: 25 })
  const exports = useReportExports({ page: list.page, per_page: list.perPage })

  const columns: Column<ReportExport>[] = [
    {
      key: 'report',
      header: 'Report',
      cell: (e) => (
        <div>
          <p className="font-medium">{e.report_label}</p>
          <p className="text-muted-foreground text-xs">
            {describe(e.params)} · {e.format.toUpperCase()}
          </p>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (e) => (
        <div className="space-y-1">
          <span className="inline-flex items-center gap-1.5">
            {(e.status === 'queued' || e.status === 'running') && (
              <Loader2 className="text-muted-foreground size-3.5 animate-spin" aria-hidden />
            )}
            <StatusBadge tone={statusTone[e.status]}>{statusLabel[e.status]}</StatusBadge>
          </span>
          {e.error && <p className="text-destructive text-xs">{e.error}</p>}
        </div>
      ),
    },
    { key: 'rows', header: 'Rows', hideBelow: 'sm', cell: (e) => <span className="tabular-nums">{e.row_count ?? '—'}</span> },
    {
      key: 'size',
      header: 'Size',
      hideBelow: 'md',
      cell: (e) => <span className="tabular-nums">{formatBytes(e.size_bytes)}</span>,
    },
    { key: 'created', header: 'Requested', hideBelow: 'md', cell: (e) => formatDateTime(e.created_at, prefs) },
    {
      key: 'expires',
      header: 'Available until',
      hideBelow: 'lg',
      cell: (e) => (e.expires_at ? formatDateTime(e.expires_at, prefs) : '—'),
    },
    {
      key: 'download',
      header: 'Download',
      cell: (e) =>
        e.downloadable ? (
          <Button asChild variant="outline" size="sm">
            <a href={exportDownloadUrl(e.id)} download={e.file_name}>
              <Download /> <span className="sr-only sm:not-sr-only">Download</span>
              <span className="sr-only"> {e.file_name}</span>
            </a>
          </Button>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Exports"
        description="Files you requested from the reports. Only you can download them; they are deleted after 7 days."
      />
      <DataTable
        caption="Your report exports"
        columns={columns}
        list={list}
        query={exports}
        rowKey={(e) => e.id}
        emptyTitle="No exports yet"
        emptyDescription="Use Export on any report to create an Excel or CSV file."
      />
    </>
  )
}
