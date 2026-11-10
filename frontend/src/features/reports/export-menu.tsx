import { Download, Loader2 } from 'lucide-react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { useCan } from '@/features/tenant-auth/hooks'
import { errorMessage } from '@/lib/forms'
import { useCreateExport, type ExportFormat, type ReportKind, type ReportParams } from './api'

/**
 * Queues a CSV or XLSX file of the report with the current filters. Files
 * are built in the background and appear on the Exports page. Hidden
 * without reports.export (the API enforces it as well).
 */
export function ExportMenu({ report, params, disabled }: { report: ReportKind; params: ReportParams; disabled?: boolean }) {
  const can = useCan()
  const create = useCreateExport()

  if (!can('reports.export')) return null

  const start = (format: ExportFormat) =>
    create.mutate(
      { report, format, ...params },
      {
        onSuccess: (e) =>
          toast.success(e.status === 'completed' ? 'Export ready.' : 'Export queued.', {
            description: (
              <Link to="/reports/exports" className="underline underline-offset-4">
                Open Exports
              </Link>
            ),
          }),
        onError: (e) => toast.error(errorMessage(e)),
      },
    )

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" disabled={disabled === true || create.isPending}>
          {create.isPending ? <Loader2 className="animate-spin" /> : <Download />} Export
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onSelect={() => start('xlsx')}>Excel (.xlsx)</DropdownMenuItem>
        <DropdownMenuItem onSelect={() => start('csv')}>CSV (.csv)</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
