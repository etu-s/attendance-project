import { useState } from 'react'
import { Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { errorMessage } from '@/lib/forms'
import { useLeaveAction, type LeaveRequest } from './api'
import { formatDays, leaveRange } from './format'
import { LeaveAttachmentsDialog } from './leave-attachments-dialog'

/** 'attachments' opens the files of the request (LeaveAttachmentsDialog). */
export type LeaveAction = { request: LeaveRequest; action: 'approve' | 'reject' | 'cancel' | 'attachments' }

const copy = {
  approve: { title: 'Approve leave', button: 'Approve', done: 'Leave approved.' },
  reject: { title: 'Reject leave', button: 'Reject', done: 'Leave rejected.' },
  cancel: { title: 'Cancel leave', button: 'Cancel leave', done: 'Leave cancelled; the days are back in the balance.' },
}

/** Approve / reject (with an optional note) or cancel one request. */
export function LeaveActionDialog({ target, onClose }: { target: LeaveAction | null; onClose: () => void }) {
  const prefs = useDisplayPreferences()
  const act = useLeaveAction()
  const [note, setNote] = useState('')

  if (!target) {
    return null
  }

  if (target.action === 'attachments') {
    return <LeaveAttachmentsDialog requestId={target.request.id} onClose={onClose} />
  }

  const { request, action } = target
  const close = () => {
    setNote('')
    onClose()
  }

  return (
    <Dialog open onOpenChange={(open) => !open && close()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{copy[action].title}</DialogTitle>
          <DialogDescription>
            {request.employee.full_name} · {request.type.name} · {leaveRange(request, prefs)} ({formatDays(request.days)})
          </DialogDescription>
        </DialogHeader>
        {request.reason && <p className="text-muted-foreground text-sm">“{request.reason}”</p>}
        {action !== 'cancel' && (
          <div className="grid gap-1.5">
            <Label htmlFor="leave-note">Note (optional)</Label>
            <Textarea id="leave-note" rows={3} maxLength={255} value={note} onChange={(e) => setNote(e.target.value)} />
          </div>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={close}>
            Back
          </Button>
          <Button
            variant={action === 'approve' ? 'default' : 'destructive'}
            disabled={act.isPending}
            onClick={() =>
              act.mutate(
                { id: request.id, action, note: note.trim() || null },
                {
                  onSuccess: () => {
                    toast.success(copy[action].done)
                    close()
                  },
                  onError: (e) => toast.error(errorMessage(e)),
                },
              )
            }
          >
            {act.isPending && <Loader2 className="animate-spin" />}
            {copy[action].button}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
