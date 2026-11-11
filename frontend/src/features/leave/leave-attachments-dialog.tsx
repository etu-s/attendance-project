import { useRef } from 'react'
import { Download, FileText, Loader2, Paperclip, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { ErrorState } from '@/components/states'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { formatBytes } from '@/features/reports/format'
import { useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { formatDateTime } from '@/lib/format'
import { errorMessage } from '@/lib/forms'
import { ATTACHMENT_LIMITS, leaveAttachmentUrl, useDeleteLeaveAttachment, useLeaveRequest, useUploadLeaveAttachment } from './api'
import { leaveRange } from './format'

/**
 * Supporting files of one leave request (e.g. a medical certificate). Reads
 * the request fresh so the list updates after each upload or removal.
 */
export function LeaveAttachmentsDialog({ requestId, onClose }: { requestId: number | null; onClose: () => void }) {
  const prefs = useDisplayPreferences()
  const request = useLeaveRequest(requestId)
  const upload = useUploadLeaveAttachment(requestId ?? 0)
  const remove = useDeleteLeaveAttachment(requestId ?? 0)
  const input = useRef<HTMLInputElement>(null)
  const files = request.data?.attachments ?? []
  const full = files.length >= ATTACHMENT_LIMITS.files

  const pick = (file: File | undefined) => {
    if (!file) return
    if (file.size > ATTACHMENT_LIMITS.bytes) {
      toast.error('The file may be at most 5 MB.')

      return
    }
    upload.mutate(file, {
      onSuccess: () => toast.success(`${file.name} attached.`),
      onError: (e) => toast.error(errorMessage(e)),
    })
  }

  return (
    <Dialog open={requestId !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Attachments</DialogTitle>
          <DialogDescription>
            {request.data
              ? `${request.data.employee.full_name} · ${request.data.type.name} · ${leaveRange(request.data, prefs)}`
              : 'Supporting documents of this request.'}
          </DialogDescription>
        </DialogHeader>

        {request.isError ? (
          <ErrorState error={request.error} onRetry={() => request.refetch()} />
        ) : request.isPending ? (
          <div className="space-y-2" role="status" aria-label="Loading">
            <Skeleton className="h-12" />
            <Skeleton className="h-12" />
          </div>
        ) : files.length === 0 ? (
          <p className="text-muted-foreground rounded-md border border-dashed px-4 py-6 text-center text-sm">No files yet.</p>
        ) : (
          <ul className="divide-y rounded-md border">
            {files.map((f) => (
              <li key={f.id} className="flex items-center gap-3 px-3 py-2">
                <FileText className="text-muted-foreground size-5 shrink-0" aria-hidden />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{f.name}</p>
                  <p className="text-muted-foreground text-xs">
                    {formatBytes(f.size_bytes)} · {f.uploaded_by?.name ?? '—'} · {formatDateTime(f.created_at, prefs)}
                  </p>
                </div>
                <Button asChild variant="ghost" size="icon" className="size-8">
                  <a href={leaveAttachmentUrl(requestId ?? 0, f.id)} download={f.name} aria-label={`Download ${f.name}`}>
                    <Download />
                  </a>
                </Button>
                {f.can_remove && (
                  <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label={`Remove ${f.name}`}
                    disabled={remove.isPending}
                    onClick={() =>
                      remove.mutate(f.id, {
                        onSuccess: () => toast.success(`${f.name} removed.`),
                        onError: (e) => toast.error(errorMessage(e)),
                      })
                    }
                  >
                    <Trash2 />
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}

        {request.data?.can_attach && (
          <p className="text-muted-foreground text-xs">
            PDF, JPG or PNG, up to 5 MB each and {ATTACHMENT_LIMITS.files} per request.
          </p>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Close
          </Button>
          {request.data?.can_attach && (
            <>
              <input
                ref={input}
                type="file"
                accept={ATTACHMENT_LIMITS.accept}
                className="sr-only"
                tabIndex={-1}
                aria-hidden
                onChange={(e) => {
                  pick(e.target.files?.[0])
                  e.target.value = ''
                }}
              />
              <Button disabled={full || upload.isPending} onClick={() => input.current?.click()}>
                {upload.isPending ? <Loader2 className="animate-spin" /> : <Paperclip />}
                {full ? 'Limit reached' : 'Attach a file'}
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
